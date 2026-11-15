<?php
declare(strict_types=1);

/**
 * Motori i raporteve statistikore.
 * $o = [from, to, komuna, school_id, book_id, teacher_id, class_id, a11y(bool), admin(bool)]
 */
/* ---------- Mbrojtja e grupeve të vogla (statistical disclosure control) ---------- */
function small_cell_min(): int { return max(0, setting_int('small_cell_min')); }
/** Numër nxënësish 1..N-1 → "<N" (0 mbetet 0) */
function small_cell($v) {
    $k = small_cell_min();
    return ($k > 1 && is_numeric($v) && (int)$v > 0 && (int)$v < $k) ? '<' . $k : $v;
}
/** Zbaton fshehjen te seksionet e raportit (përveç tabelës sipas nxënësit, që e sheh vetëm mësuesi i klasës) */
function report_suppress(array $R): array {
    $k = small_cell_min();
    if ($k < 2) return $R;
    $personCols = '/^(Nxënës|Vajza|Djem|Me NV|Aktivë|Lexues|Nxënës me NV|Students|Girls|Boys|SEN|Active|…with SEN)$/u';
    foreach ($R['sections'] as &$sec) {
        if (in_array($sec['key'] ?? '', ['students', 'tickets', 'hard'], true) || empty($sec['rows'])) continue;
        $cols = array_map(fn($c) => $c[0], $sec['cols']);
        $nIdx = array_search('Nxënës', $cols, true);
        foreach ($sec['rows'] as &$row) {
            $vals = array_values($row);
            $groupSmall = $nIdx !== false && is_numeric($vals[$nIdx]) && (int)$vals[$nIdx] > 0 && (int)$vals[$nIdx] < $k;
            foreach ($vals as $ci => &$v) {
                if ($ci === 0 || ($sec['cols'][$ci][1] ?? 'text') === 'text') continue;
                if (preg_match($personCols, $cols[$ci] ?? '')) $v = small_cell($v);
                elseif ($groupSmall && in_array($sec['key'] ?? '', ['gender', 'sen', 'sen_types', 'tongue'], true)) $v = '—';   // treguesit e një grupi shumë të vogël
            }
            unset($v);
            $row = $vals;
        }
        unset($row);
        if (!empty($sec['chart'])) foreach ($sec['chart'] as $v) if ($v > 0 && $v < $k) { $sec['chart'] = null; break; }
        $sec['note'] = trim(($sec['note'] ?? '') . ' Grupet me më pak se ' . $k . ' nxënës shfaqen si "<' . $k . '" për të mbrojtur privatësinë.');
    }
    unset($sec);
    return $R;
}

function report_build(array $o): array {
    $from = $o['from'] . ' 00:00:00';
    $to = $o['to'] . ' 23:59:59';
    $book = (int)($o['book_id'] ?? 0);
    $a11yOn = !empty($o['a11y']) && db_table_exists('interaction_events');
    $hasSessions = db_table_exists('learning_sessions');
    $hasDemo = db_column_exists('users', 'gender');

    /* ---------- Fushëveprimi (cilët nxënës) ---------- */
    $w = ["st.role='student'", 'st.active=1'];
    $p = [];
    if (!empty($o['school_id'])) { $w[] = 'st.school_id = ?'; $p[] = (int)$o['school_id']; }
    if (!empty($o['komuna'])) { $w[] = 's.city = ?'; $p[] = $o['komuna']; }
    if (!empty($o['teacher_id'])) { $w[] = 'EXISTS (SELECT 1 FROM class_students x JOIN classes y ON y.id=x.class_id WHERE x.student_id=st.id AND y.teacher_id=?)'; $p[] = (int)$o['teacher_id']; }
    if (!empty($o['class_id'])) { $w[] = 'EXISTS (SELECT 1 FROM class_students x WHERE x.student_id=st.id AND x.class_id=?)'; $p[] = (int)$o['class_id']; }
    $W = implode(' AND ', $w);
    $scope = "SELECT st.id FROM users st LEFT JOIN schools s ON s.id=st.school_id WHERE $W";

    $demoCols = $hasDemo ? 'st.gender, st.special_needs, st.special_needs_type, st.mother_tongue' : 'NULL gender, NULL special_needs, NULL special_needs_type, NULL mother_tongue';
    $S = [];
    foreach (qa("SELECT st.id, st.full_name, $demoCols, st.school_id, s.name school, s.city komuna FROM users st LEFT JOIN schools s ON s.id=st.school_id WHERE $W", $p) as $r) {
        $S[(int)$r['id']] = $r + ['secs' => 0, 'days' => 0, 'sessions' => 0, 'attempts' => 0, 'best_sum' => 0, 'best_n' => 0, 'pages' => 0, 'a11y' => [], 'clicks' => 0];
    }
    $bk = $book ? ' AND book_id = ' . $book : '';
    foreach (qa("SELECT student_id, SUM(seconds) s, COUNT(DISTINCT day) d FROM usage_daily WHERE day BETWEEN ? AND ? $bk AND student_id IN ($scope) GROUP BY student_id", [$o['from'], $o['to'], ...$p]) as $r) {
        $S[(int)$r['student_id']]['secs'] = (int)$r['s']; $S[(int)$r['student_id']]['days'] = (int)$r['d'];
    }
    if ($hasSessions) foreach (qa("SELECT student_id, COUNT(*) n FROM learning_sessions WHERE started_at BETWEEN ? AND ? $bk AND student_id IN ($scope) GROUP BY student_id", [$from, $to, ...$p]) as $r) {
        $S[(int)$r['student_id']]['sessions'] = (int)$r['n'];
    }
    foreach (qa("SELECT student_id, COUNT(*) n FROM quiz_attempts WHERE created_at BETWEEN ? AND ? $bk AND student_id IN ($scope) GROUP BY student_id", [$from, $to, ...$p]) as $r) {
        $S[(int)$r['student_id']]['attempts'] = (int)$r['n'];
    }
    foreach (qa("SELECT student_id, SUM(best) b, COUNT(*) n FROM (SELECT student_id, book_id, section_id, MAX(score_pct) best FROM quiz_attempts
                 WHERE created_at BETWEEN ? AND ? $bk AND score_pct IS NOT NULL AND student_id IN ($scope) GROUP BY student_id, book_id, section_id) x GROUP BY student_id", [$from, $to, ...$p]) as $r) {
        $S[(int)$r['student_id']]['best_sum'] = (float)$r['b']; $S[(int)$r['student_id']]['best_n'] = (int)$r['n'];
    }
    foreach (qa("SELECT student_id, COUNT(*) n FROM page_views WHERE last_seen >= ? AND first_seen <= ? $bk AND student_id IN ($scope) GROUP BY student_id", [$from, $to, ...$p]) as $r) {
        $S[(int)$r['student_id']]['pages'] = (int)$r['n'];
    }
    $a11yList = a11y_features();
    if ($a11yOn) {
        $in = "'" . implode("','", $a11yList) . "'";
        foreach (qa("SELECT student_id, feature, COUNT(*) n FROM interaction_events WHERE created_at BETWEEN ? AND ? $bk AND feature IN ($in) AND student_id IN ($scope) GROUP BY student_id, feature", [$from, $to, ...$p]) as $r) {
            $S[(int)$r['student_id']]['a11y'][$r['feature']] = (int)$r['n'];
        }
        foreach (qa("SELECT student_id, COUNT(*) n FROM interaction_events WHERE created_at BETWEEN ? AND ? $bk AND student_id IN ($scope) GROUP BY student_id", [$from, $to, ...$p]) as $r) {
            $S[(int)$r['student_id']]['clicks'] = (int)$r['n'];
        }
    }

    /* ---------- Agregimi ---------- */
    $agg = function (array $ids) use ($S): array {
        $a = ['n' => 0, 'active' => 0, 'secs' => 0, 'sessions' => 0, 'attempts' => 0, 'best_sum' => 0, 'best_n' => 0, 'pages' => 0, 'a11y_users' => 0, 'girls' => 0, 'boys' => 0, 'sen' => 0, 'clicks' => 0];
        foreach ($ids as $id) {
            if (!isset($S[$id])) continue;
            $s = $S[$id];
            $a['n']++;
            if ($s['secs'] > 0 || $s['attempts'] > 0) $a['active']++;
            $a['secs'] += $s['secs']; $a['sessions'] += $s['sessions']; $a['attempts'] += $s['attempts'];
            $a['best_sum'] += $s['best_sum']; $a['best_n'] += $s['best_n']; $a['pages'] += $s['pages']; $a['clicks'] += $s['clicks'];
            if ($s['a11y']) $a['a11y_users']++;
            if ($s['gender'] === 'F') $a['girls']++;
            if ($s['gender'] === 'M') $a['boys']++;
            if ((int)$s['special_needs'] === 1) $a['sen']++;
        }
        $a['part'] = $a['n'] ? round($a['active'] * 100 / $a['n'], 1) : 0.0;
        $a['hours'] = round($a['secs'] / 3600, 1);
        $a['min_active'] = $a['active'] ? (int)round($a['secs'] / 60 / $a['active']) : 0;
        $a['acc'] = $a['best_n'] ? round($a['best_sum'] / $a['best_n'], 1) : null;
        $a['a11y_pct'] = $a['active'] ? round($a['a11y_users'] * 100 / $a['active'], 1) : 0.0;
        return $a;
    };
    $group = function (callable $key) use ($S): array {
        $g = [];
        foreach ($S as $id => $s) { $k = $key($s); if ($k !== null) $g[$k][] = $id; }
        return $g;
    };
    $all = array_keys($S);
    $T = $agg($all);

    // kolonat standarde të grupeve demografike
    $demoCols = [['Grupi', 'text'], ['Nxënës', 'int'], ['% e totalit', 'pct'], ['Aktivë', 'int'], ['Pjesëmarrja %', 'pct'], ['Orë mësimi', 'hours'],
        ['Min. / nxënës aktiv', 'int'], ['Seanca', 'int'], ['Tentativa', 'int'], ['Saktësia %', 'pct']];
    if ($a11yOn) $demoCols[] = ['Përdorin qasshmërinë %', 'pct'];
    $demoRow = function (string $label, array $a) use ($T, $a11yOn): array {
        $r = [$label, $a['n'], $T['n'] ? round($a['n'] * 100 / $T['n'], 1) : 0.0, $a['active'], $a['part'], $a['hours'], $a['min_active'], $a['sessions'], $a['attempts'], $a['acc']];
        if ($a11yOn) $r[] = $a['a11y_pct'];
        return $r;
    };

    $sections = [];

    /* 1. Gjinia */
    $g = $group(fn($s) => $s['gender'] ?: '_');
    $rows = [];
    foreach (['F' => '👧 Vajza', 'M' => '👦 Djem', '_' => '❔ Gjinia e paplotësuar'] as $k => $l) if (!empty($g[$k]) || $k !== '_') $rows[] = $demoRow($l, $agg($g[$k] ?? []));
    $rows[] = $demoRow('Gjithsej', $T);
    $sections[] = ['key' => 'gender', 'title' => '👧👦 Nxënësit sipas gjinisë', 'cols' => $demoCols, 'rows' => $rows, 'total_last' => true,
        'chart' => ['Vajza' => $agg($g['F'] ?? [])['n'], 'Djem' => $agg($g['M'] ?? [])['n'], 'Pa të dhëna' => $agg($g['_'] ?? [])['n']]];

    /* 2. Nevojat e veçanta */
    $g = $group(fn($s) => $s['special_needs'] === null ? '_' : ((int)$s['special_needs'] === 1 ? 'Y' : 'N'));
    $senIds = $g['Y'] ?? [];
    $rows = [];
    $rows[] = $demoRow('♿ Me nevoja të veçanta', $agg($senIds));
    $rows[] = $demoRow('   — prej tyre vajza', $agg(array_values(array_filter($senIds, fn($id) => $S[$id]['gender'] === 'F'))));
    $rows[] = $demoRow('   — prej tyre djem', $agg(array_values(array_filter($senIds, fn($id) => $S[$id]['gender'] === 'M'))));
    $rows[] = $demoRow('Pa nevoja të veçanta', $agg($g['N'] ?? []));
    if (!empty($g['_'])) $rows[] = $demoRow('❔ E paplotësuar', $agg($g['_']));
    $rows[] = $demoRow('Gjithsej', $T);
    $sections[] = ['key' => 'sen', 'title' => '♿ Nxënësit me nevoja të veçanta', 'cols' => $demoCols, 'rows' => $rows, 'total_last' => true,
        'chart' => ['Me NV' => count($senIds), 'Pa NV' => count($g['N'] ?? []), 'Pa të dhëna' => count($g['_'] ?? [])]];

    if ($senIds) {
        $g = $group(fn($s) => (int)$s['special_needs'] === 1 ? ($s['special_needs_type'] ?: 'E pa specifikuar') : null);
        ksort($g);
        $rows = [];
        foreach ($g as $type => $ids) $rows[] = $demoRow($type, $agg($ids));
        $sections[] = ['key' => 'sen_types', 'title' => '♿ Sipas llojit të nevojës së veçantë', 'cols' => $demoCols, 'rows' => $rows];
    }

    /* 3. Gjuha amtare */
    $g = $group(fn($s) => $s['mother_tongue'] ?: '❔ E paplotësuar');
    if (count($g) > 1 || !isset($g['❔ E paplotësuar'])) {
        uasort($g, fn($a, $b) => count($b) <=> count($a));
        $rows = [];
        foreach ($g as $k => $ids) $rows[] = $demoRow($k, $agg($ids));
        $sections[] = ['key' => 'tongue', 'title' => '🗣️ Sipas gjuhës amtare', 'cols' => $demoCols, 'rows' => $rows];
    }

    $geoCols = [['Nxënës', 'int'], ['Vajza', 'int'], ['Djem', 'int'], ['Me NV', 'int'], ['Aktivë', 'int'], ['Pjesëmarrja %', 'pct'], ['Orë mësimi', 'hours'], ['Tentativa', 'int'], ['Saktësia %', 'pct']];
    $geoRow = fn(array $a) => [$a['n'], $a['girls'], $a['boys'], $a['sen'], $a['active'], $a['part'], $a['hours'], $a['attempts'], $a['acc']];

    /* 4. Komuna (vetëm admin, kur nuk është filtruar një shkollë) */
    if (!empty($o['admin']) && empty($o['school_id'])) {
        $g = $group(fn($s) => $s['komuna'] ?: '❔ Pa komunë');
        ksort($g);
        $schoolsBy = []; $teachersBy = [];
        foreach (qa('SELECT city, COUNT(*) n FROM schools WHERE active=1 GROUP BY city') as $r) $schoolsBy[$r['city'] ?: '❔ Pa komunë'] = (int)$r['n'];
        foreach (qa("SELECT COALESCE(municipality, '') m, COUNT(*) n FROM users WHERE role='teacher' AND active=1 GROUP BY m") as $r) $teachersBy[$r['m'] ?: '❔ Pa komunë'] = (int)$r['n'];
        $rows = [];
        foreach ($g as $k => $ids) $rows[] = [$k, $schoolsBy[$k] ?? 0, $teachersBy[$k] ?? 0, ...$geoRow($agg($ids))];
        $rows[] = ['Gjithsej', array_sum($schoolsBy), array_sum($teachersBy), ...$geoRow($T)];
        $sections[] = ['key' => 'komuna', 'title' => '🗺️ Sipas komunës', 'cols' => [['Komuna', 'text'], ['Shkolla', 'int'], ['Mësues', 'int'], ...$geoCols], 'rows' => $rows, 'total_last' => true,
            'note' => 'Nxënësit renditen sipas komunës së shkollës; mësuesit sipas komunës së regjistruar te profili i tyre.'];
    }

    /* 5. Shkolla */
    if (!empty($o['admin'])) {
        $g = $group(fn($s) => $s['school_id'] ? (string)$s['school_id'] : null);
        $meta = [];
        foreach (qa("SELECT s.id, s.name, s.city, (SELECT COUNT(*) FROM users t WHERE t.school_id=s.id AND t.role='teacher' AND t.active=1) teachers,
                (SELECT COUNT(*) FROM classes c WHERE c.school_id=s.id) classes,
                (SELECT COUNT(*) FROM tickets k WHERE k.school_id=s.id AND k.created_at BETWEEN ? AND ?) tickets FROM schools s", [$from, $to]) as $r) $meta[(string)$r['id']] = $r;
        $rows = [];
        foreach ($g as $sid => $ids) {
            $m = $meta[$sid] ?? ['name' => '?', 'city' => '', 'teachers' => 0, 'classes' => 0, 'tickets' => 0];
            $rows[] = [$m['name'], $m['city'], (int)$m['teachers'], (int)$m['classes'], ...$geoRow($agg($ids)), (int)$m['tickets']];
        }
        usort($rows, fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $sections[] = ['key' => 'school', 'title' => '🏫 Sipas shkollës', 'cols' => [['Shkolla', 'text'], ['Komuna', 'text'], ['Mësues', 'int'], ['Klasa', 'int'], ...$geoCols, ['Tiketa', 'int']], 'rows' => $rows];
    }

    /* 6. Klasa / mësuesi */
    $cw = [];
    $cp = [];
    if (!empty($o['teacher_id'])) { $cw[] = 'c.teacher_id = ?'; $cp[] = (int)$o['teacher_id']; }
    if (!empty($o['school_id'])) { $cw[] = 'c.school_id = ?'; $cp[] = (int)$o['school_id']; }
    if (!empty($o['class_id'])) { $cw[] = 'c.id = ?'; $cp[] = (int)$o['class_id']; }
    if (!empty($o['komuna'])) { $cw[] = 's.city = ?'; $cp[] = $o['komuna']; }
    $members = [];
    foreach (qa('SELECT cs.class_id, cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id JOIN schools s ON s.id=c.school_id' . ($cw ? ' WHERE ' . implode(' AND ', $cw) : ''), $cp) as $r) $members[(int)$r['class_id']][] = (int)$r['student_id'];
    $rows = [];
    foreach (qa('SELECT c.id, c.name, c.grade, t.full_name teacher, s.name school FROM classes c JOIN users t ON t.id=c.teacher_id JOIN schools s ON s.id=c.school_id'
        . ($cw ? ' WHERE ' . implode(' AND ', $cw) : '') . ' ORDER BY s.name, c.grade, c.name', $cp) as $c) {
        $rows[] = [$c['name'], $c['teacher'], $c['school'], (int)$c['grade'], ...$geoRow($agg($members[(int)$c['id']] ?? []))];
    }
    if ($rows) $sections[] = ['key' => 'class', 'title' => '🏷️ Sipas klasës dhe mësuesit', 'cols' => [['Klasa', 'text'], ['Mësuesi', 'text'], ['Shkolla', 'text'], ['Viti', 'int'], ...$geoCols], 'rows' => $rows];

    /* 6b. Sipas nxënësit (raporti i mësuesit) */
    if (!empty($o['teacher_id'])) {
        $rows = [];
        $list = $S;
        uasort($list, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));
        foreach ($list as $s) {
            $rows[] = [$s['full_name'], gender_label($s['gender']), (int)$s['special_needs'] === 1 ? 'Po' : '—', $s['days'], $s['sessions'],
                round($s['secs'] / 3600, 1), $s['pages'], $s['attempts'], $s['best_n'] ? round($s['best_sum'] / $s['best_n'], 1) : null];
        }
        $sections[] = ['key' => 'students', 'title' => '🧒 Sipas nxënësit', 'cols' => [['Nxënësi', 'text'], ['Gjinia', 'text'], ['NV', 'text'], ['Ditë aktive', 'int'], ['Seanca', 'int'],
            ['Orë', 'hours'], ['Faqe', 'int'], ['Tentativa', 'int'], ['Saktësia %', 'pct']], 'rows' => $rows];
    }

    /* 7. Librat dhe ushtrimet më të vështira */
    $rows = [];
    foreach (qa("SELECT b.id, b.title, b.page_count, b.activity_count,
        (SELECT COUNT(DISTINCT student_id) FROM usage_daily u WHERE u.book_id=b.id AND u.day BETWEEN ? AND ? AND u.student_id IN ($scope)) readers,
        (SELECT COALESCE(SUM(seconds),0) FROM usage_daily u WHERE u.book_id=b.id AND u.day BETWEEN ? AND ? AND u.student_id IN ($scope)) secs,
        (SELECT COUNT(*) FROM quiz_attempts q WHERE q.book_id=b.id AND q.created_at BETWEEN ? AND ? AND q.student_id IN ($scope)) att,
        (SELECT AVG(score_pct) FROM quiz_attempts q WHERE q.book_id=b.id AND q.created_at BETWEEN ? AND ? AND q.score_pct IS NOT NULL AND q.student_id IN ($scope)) acc
        FROM books b" . ($book ? ' WHERE b.id=' . $book : '') . ' ORDER BY b.title',
        [$o['from'], $o['to'], ...$p, $o['from'], $o['to'], ...$p, $from, $to, ...$p, $from, $to, ...$p]) as $b) {
        if (!(int)$b['readers'] && !(int)$b['att']) continue;
        $rows[] = [$b['title'], (int)$b['page_count'], (int)$b['activity_count'], (int)$b['readers'], round((int)$b['secs'] / 3600, 1),
            (int)$b['readers'] ? (int)round((int)$b['secs'] / 60 / (int)$b['readers']) : 0, (int)$b['att'], $b['acc'] !== null ? round((float)$b['acc'], 1) : null];
    }
    $sections[] = ['key' => 'books', 'title' => '📚 Sipas librit', 'cols' => [['Libri', 'text'], ['Faqe', 'int'], ['Ushtrime', 'int'], ['Lexues', 'int'], ['Orë mësimi', 'hours'], ['Min. / lexues', 'int'], ['Tentativa', 'int'], ['Saktësia %', 'pct']], 'rows' => $rows];

    $rows = [];
    foreach (qa("SELECT b.title, bp.page_number, bp.title ptitle, bp.section_type, COUNT(DISTINCT q.student_id) st, COUNT(*) att, AVG(q.score_pct) acc
        FROM quiz_attempts q JOIN books b ON b.id=q.book_id JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
        WHERE q.created_at BETWEEN ? AND ? AND q.score_pct IS NOT NULL AND q.student_id IN ($scope)" . ($book ? ' AND q.book_id=' . $book : '') . '
        GROUP BY q.book_id, q.section_id HAVING st >= 2 ORDER BY acc ASC, att DESC LIMIT 10', [$from, $to, ...$p]) as $r) {
        $rows[] = [$r['title'], $r['page_number'] ? (int)$r['page_number'] : null, $r['ptitle'], section_type_label($r['section_type']), (int)$r['st'], round((int)$r['att'] / max(1, (int)$r['st']), 1), round((float)$r['acc'], 1)];
    }
    if ($rows) $sections[] = ['key' => 'hard', 'title' => '🎯 10 ushtrimet më të vështira', 'note' => 'Ushtrimet me saktësinë mesatare më të ulët (të paktën 2 nxënës).',
        'cols' => [['Libri', 'text'], ['Faqja', 'int'], ['Ushtrimi', 'text'], ['Lloji', 'text'], ['Nxënës', 'int'], ['Tentativa / nxënës', 'hours'], ['Saktësia %', 'pct']], 'rows' => $rows];

    /* 8. Qasshmëria (vetëm admin) */
    if ($a11yOn) {
        $rows = [];
        foreach ($a11yList as $f) {
            $uses = 0; $users = 0; $senU = 0; $girls = 0; $boys = 0;
            foreach ($S as $s) {
                if (empty($s['a11y'][$f])) continue;
                $uses += $s['a11y'][$f]; $users++;
                if ((int)$s['special_needs'] === 1) $senU++;
                if ($s['gender'] === 'F') $girls++;
                if ($s['gender'] === 'M') $boys++;
            }
            $rows[] = [feature_catalog()[$f][1], $uses, $users, $T['active'] ? round($users * 100 / $T['active'], 1) : 0.0, $senU, count($senIds) ? round($senU * 100 / count($senIds), 1) : 0.0, $girls, $boys];
        }
        usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
        $sections[] = ['key' => 'a11y', 'title' => '♿ Përdorimi i veçorive të qasshmërisë', 'note' => 'Sa nxënës i përdorin veçoritë e ADT Studio — veçanërisht nxënësit me nevoja të veçanta.',
            'cols' => [['Veçoria', 'text'], ['Përdorime', 'int'], ['Nxënës', 'int'], ['% e aktivëve', 'pct'], ['Nxënës me NV', 'int'], ['% e nxënësve me NV', 'pct'], ['Vajza', 'int'], ['Djem', 'int']], 'rows' => $rows];
    }

    /* 9. Tiketat */
    $tw = ['t.created_at BETWEEN ? AND ?'];
    $tp = [$from, $to];
    if (!empty($o['teacher_id'])) { $tw[] = "(t.assigned_to = ? OR t.created_by = ?)"; $tp[] = (int)$o['teacher_id']; $tp[] = (int)$o['teacher_id']; }
    if (!empty($o['school_id'])) { $tw[] = 't.school_id = ?'; $tp[] = (int)$o['school_id']; }
    if (!empty($o['komuna'])) { $tw[] = 't.school_id IN (SELECT id FROM schools WHERE city = ?)'; $tp[] = $o['komuna']; }
    $rows = [];
    foreach (qa('SELECT t.category, COUNT(*) n, SUM(t.status=\'open\') op, SUM(t.status=\'in_progress\') ip, SUM(t.status=\'closed\') cl,
        AVG((SELECT TIMESTAMPDIFF(MINUTE, t.created_at, MIN(m.created_at)) FROM ticket_messages m WHERE m.ticket_id=t.id AND m.user_id<>t.created_by)) resp
        FROM tickets t WHERE ' . implode(' AND ', $tw) . ' GROUP BY t.category ORDER BY n DESC', $tp) as $r) {
        $c = ticket_categories()[$r['category']] ?? ['💬', $r['category']];
        $rows[] = [$c[1], (int)$r['n'], (int)$r['op'], (int)$r['ip'], (int)$r['cl'], $r['resp'] !== null ? round((float)$r['resp'] / 60, 1) : null];
    }
    if ($rows) {
        $tot = ['Gjithsej', 0, 0, 0, 0, null];
        foreach ($rows as $r) for ($i = 1; $i <= 4; $i++) $tot[$i] += $r[$i];
        $rows[] = $tot;
    }
    $sections[] = ['key' => 'tickets', 'title' => '🎫 Tiketat (problemet e raportuara)', 'cols' => [['Kategoria', 'text'], ['Gjithsej', 'int'], ['Të hapura', 'int'], ['Në proces', 'int'], ['Të mbyllura', 'int'], ['Përgjigja e parë (orë)', 'hours']], 'rows' => $rows, 'total_last' => (bool)$rows];

    /* Ecuria ditore */
    $daily = [];
    foreach (qa("SELECT day, SUM(seconds) s, COUNT(DISTINCT student_id) n FROM usage_daily WHERE day BETWEEN ? AND ? $bk AND student_id IN ($scope) GROUP BY day ORDER BY day", [$o['from'], $o['to'], ...$p]) as $r) {
        $daily[$r['day']] = ['min' => (int)round((int)$r['s'] / 60), 'students' => (int)$r['n']];
    }

    $kpi = [
        ['🧒 Nxënës', $T['n'], 'int'],
        ['👧 Vajza', $T['girls'], 'int'],
        ['👦 Djem', $T['boys'], 'int'],
        ['♿ Me nevoja të veçanta', $T['sen'], 'int'],
        ['⚡ Aktivë në periudhë', $T['active'], 'int'],
        ['📈 Pjesëmarrja', $T['part'], 'pct'],
        ['⏱️ Orë mësimi', $T['hours'], 'hours'],
        ['🔁 Seanca leximi', $T['sessions'], 'int'],
        ['📝 Tentativa ushtrimesh', $T['attempts'], 'int'],
        ['🎯 Saktësia mesatare', $T['acc'], 'pct'],
    ];
    if ($a11yOn) $kpi[] = ['♿ Përdorin qasshmërinë', $T['a11y_pct'], 'pct'];

    return report_suppress(['kpi' => $kpi, 'sections' => $sections, 'daily' => $daily, 'total' => $T, 'demo_ready' => $hasDemo]);
}

function report_fmt($v, string $type): string {
    if ($v === null || $v === '') return '—';
    return match ($type) {
        'int' => number_format((float)$v, 0, ',', '.'),
        'pct' => number_format((float)$v, 1, ',', '.') . '%',
        'hours' => number_format((float)$v, 1, ',', '.'),
        default => (string)$v,
    };
}

/** Shfaq raportin si HTML (për ekran dhe printim/PDF) */
function report_html(array $R, string $title, array $filtersText): string {
    ob_start(); ?>
    <div class="report">
      <header class="report-head">
        <div><h1>📊 <?= e($title) ?></h1>
          <p class="muted"><?= e(implode(' · ', $filtersText)) ?></p></div>
        <div class="small muted" style="text-align:right"><?= e(cfg('app_name')) ?><br>Gjeneruar më <?= e(date('d.m.Y H:i')) ?><br><?= e(current_user()['full_name'] ?? '') ?></div>
      </header>
      <?php if (!$R['demo_ready']): ?><div class="flash flash-info no-print">ℹ️ Përditësoni bazën që të shfaqen të dhënat për gjininë dhe nevojat e veçanta.</div><?php endif; ?>
      <div class="report-kpis">
        <?php foreach ($R['kpi'] as [$l, $v, $t]): ?><div class="rk"><div class="lbl"><?= e($l) ?></div><div class="val"><?= e(report_fmt($v, $t)) ?></div></div><?php endforeach; ?>
      </div>
      <?php if ($R['daily']):
          $pts = [];
          foreach ($R['daily'] as $d => $x) $pts[] = ['label' => date('d.m', strtotime($d)), 'value' => $x['min']];
          if (count($pts) > 60) $pts = array_slice($pts, -60); ?>
        <section class="report-sec"><h2>📅 Minuta mësimi në ditë</h2><?= bar_chart($pts, 'min', 'Minuta mësimi në ditë') ?></section>
      <?php endif; ?>
      <?php foreach ($R['sections'] as $sec): ?>
        <section class="report-sec">
          <h2><?= e($sec['title']) ?></h2>
          <?php if (!empty($sec['note'])): ?><p class="small muted"><?= e($sec['note']) ?></p><?php endif; ?>
          <?php if (!empty($sec['chart']) && array_sum($sec['chart']) > 0):
              $tot = array_sum($sec['chart']); $colors = ['#e05a9b', '#2f5bea', '#c9cfdc']; $i = 0; ?>
            <div class="stackbar" role="img" aria-label="<?= e($sec['title']) ?>">
              <?php foreach ($sec['chart'] as $l => $v): if (!$v) { $i++; continue; } ?>
                <span style="width:<?= round($v * 100 / $tot, 2) ?>%;background:<?= $colors[$i++ % 3] ?>" title="<?= e($l . ': ' . $v) ?>"><?= e($l) ?> <?= $v ?> (<?= round($v * 100 / $tot) ?>%)</span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!$sec['rows']): ?><p class="muted">Nuk ka të dhëna për këtë periudhë.</p><?php else: ?>
          <div class="table-wrap"><table class="t report-t">
            <thead><tr><?php foreach ($sec['cols'] as [$c, $t]): ?><th class="<?= $t === 'text' ? '' : 'num' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($sec['rows'] as $ri => $row): $isTot = !empty($sec['total_last']) && $ri === count($sec['rows']) - 1; ?>
              <tr class="<?= $isTot ? 'tot' : '' ?>"><?php foreach (array_values($row) as $ci => $v): $t = $sec['cols'][$ci][1] ?? 'text'; ?>
                <td class="<?= $t === 'text' ? '' : 'num' ?>"><?= e(report_fmt($v, $t)) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
      <p class="small muted">Shënim: "Aktivë" = nxënës me kohë leximi ose ushtrime në periudhë. Saktësia = mesatarja e tentativës më të mirë për çdo ushtrim të vlerësuar automatikisht. Të dhënat për nevojat e veçanta janë konfidenciale — ky raport i paraqet vetëm të përmbledhura.</p>
    </div>
    <?php return (string)ob_get_clean();
}

/** Eksport Excel: një fletë për çdo seksion + përmbledhja */
function report_xlsx(array $R, string $title, array $filtersText, string $filename): never {
    $sum = [[$title], [implode(' · ', $filtersText)], ['Gjeneruar më ' . date('d.m.Y H:i')], [], ['Treguesi', 'Vlera']];
    foreach ($R['kpi'] as [$l, $v, $t]) $sum[] = [trim(preg_replace('/^[^\p{L}]+/u', '', $l)), $v];
    $sheets = [['name' => 'Përmbledhje', 'rows' => $sum, 'title_rows' => [0], 'bold_rows' => [4], 'widths' => [38, 16]]];
    foreach ($R['sections'] as $sec) {
        $rows = [[trim(preg_replace('/^[^\p{L}\d]+/u', '', $sec['title']))], []];
        $rows[] = array_map(fn($c) => $c[0], $sec['cols']);
        foreach ($sec['rows'] as $r) $rows[] = array_map(fn($v) => is_string($v) ? trim(preg_replace('/^[^\p{L}\d—\-]+/u', '', $v)) : $v, array_values($r));
        $bold = [2];
        if (!empty($sec['total_last']) && $sec['rows']) $bold[] = count($rows) - 1;
        $widths = array_map(fn($c) => $c[1] === 'text' ? 30 : 14, $sec['cols']);
        $sheets[] = ['name' => trim(preg_replace('/^[^\p{L}\d]+/u', '', $sec['title'])), 'rows' => $rows, 'title_rows' => [0], 'bold_rows' => $bold, 'widths' => $widths];
    }
    if ($R['daily']) {
        $rows = [['Ecuria ditore'], [], ['Data', 'Minuta mësimi', 'Nxënës aktivë']];
        foreach ($R['daily'] as $d => $x) $rows[] = [date('d.m.Y', strtotime($d)), $x['min'], $x['students']];
        $sheets[] = ['name' => 'Ditore', 'rows' => $rows, 'title_rows' => [0], 'bold_rows' => [2], 'widths' => [14, 16, 16]];
    }
    xlsx_send($filename, $sheets);
}
