<?php
declare(strict_types=1);

/**
 * Raporti për palët e interesit (në anglisht): trajnimi i mësuesve → përdorimi i platformës →
 * përfshirja e nxënësve → progresi me librat ADT Studio.
 * $o = [from, to, training_id, komuna, org, author, background, recs_extra]
 */
function training_report_build(array $o): array {
    $from = $o['from']; $to = $o['to'];
    $fromDt = "$from 00:00:00"; $toDt = "$to 23:59:59";
    $tid = (int)($o['training_id'] ?? 0);
    $komuna = (string)($o['komuna'] ?? '');
    $th = setting_int('cert_threshold') ?: 90;
    $hasEvents = db_table_exists('interaction_events');
    $hasSessions = db_table_exists('learning_sessions');

    /* ---------- Trainings ---------- */
    $tw = $tid ? 't.id = ' . $tid : '1=1';
    $sessions = qa("SELECT t.*, COUNT(a.teacher_id) registered, SUM(a.status IN ('attended','completed')) attended, SUM(a.status='completed') completed,
        SUM(a.status='absent') absent, AVG(a.pre_score) pre, AVG(a.post_score) post, AVG(a.satisfaction) sat, SUM(a.certificate) certs
        FROM trainings t LEFT JOIN training_attendance a ON a.training_id=t.id WHERE $tw GROUP BY t.id ORDER BY t.start_date");
    $att = qa("SELECT a.*, t.start_date, t.hours FROM training_attendance a JOIN trainings t ON t.id=a.training_id WHERE $tw");

    /* ---------- Teachers in scope ---------- */
    $tp = [];
    $twhere = "u.role='teacher' AND u.active=1";
    if ($komuna !== '') { $twhere .= ' AND (u.municipality = ? OR s.city = ?)'; $tp = [$komuna, $komuna]; }
    $teachers = [];
    foreach (qa("SELECT u.id, u.full_name, u.municipality, u.last_login, u.email, s.name school, s.city school_city FROM users u LEFT JOIN schools s ON s.id=u.school_id WHERE $twhere ORDER BY u.full_name", $tp) as $t) {
        $teachers[(int)$t['id']] = $t + ['trained' => false, 'registered' => false, 'training_date' => null, 'training_hours' => 0.0, 'pre' => null, 'post' => null, 'sat' => null, 'cert' => false,
            'classes' => 0, 'first_class' => null, 'students' => [], 'books' => 0, 'assignments' => 0, 'graded' => 0];
    }
    foreach ($att as $a) {
        $id = (int)$a['teacher_id'];
        if (!isset($teachers[$id])) continue;
        $T = &$teachers[$id];
        $T['registered'] = true;
        if (in_array($a['status'], ['attended', 'completed'], true)) {
            $T['trained'] = true;
            $T['training_hours'] += (float)$a['hours'];
            if (!$T['training_date'] || $a['start_date'] < $T['training_date']) $T['training_date'] = $a['start_date'];
        }
        if ($a['pre_score'] !== null) $T['pre'] = (int)$a['pre_score'];
        if ($a['post_score'] !== null) $T['post'] = (int)$a['post_score'];
        if ($a['satisfaction'] !== null) $T['sat'] = (int)$a['satisfaction'];
        if ((int)$a['certificate']) $T['cert'] = true;
        unset($T);
    }
    $tids = array_keys($teachers) ?: [0];
    $tin = implode(',', $tids);
    foreach (qa("SELECT teacher_id, COUNT(*) n, MIN(created_at) first FROM classes WHERE teacher_id IN ($tin) GROUP BY teacher_id") as $r) {
        $teachers[(int)$r['teacher_id']]['classes'] = (int)$r['n']; $teachers[(int)$r['teacher_id']]['first_class'] = $r['first'];
    }
    $classStudents = []; $classBooks = []; $classTeacher = [];
    foreach (qa("SELECT c.id, c.teacher_id FROM classes c WHERE c.teacher_id IN ($tin)") as $c) $classTeacher[(int)$c['id']] = (int)$c['teacher_id'];
    $cin = implode(',', array_keys($classTeacher) ?: [0]);
    foreach (qa("SELECT cs.class_id, cs.student_id FROM class_students cs JOIN users s ON s.id=cs.student_id WHERE s.active=1 AND cs.class_id IN ($cin)") as $r) $classStudents[(int)$r['class_id']][] = (int)$r['student_id'];
    foreach (qa("SELECT class_id, book_id FROM class_books WHERE class_id IN ($cin)") as $r) $classBooks[(int)$r['class_id']][] = (int)$r['book_id'];
    foreach ($classTeacher as $cid => $t) {
        foreach ($classStudents[$cid] ?? [] as $sid) $teachers[$t]['students'][$sid] = true;
        $teachers[$t]['books'] += count($classBooks[$cid] ?? []);
    }
    if (db_table_exists('assignments')) foreach (qa("SELECT c.teacher_id, COUNT(*) n FROM assignments a JOIN classes c ON c.id=a.class_id WHERE c.teacher_id IN ($tin) GROUP BY c.teacher_id") as $r) $teachers[(int)$r['teacher_id']]['assignments'] = (int)$r['n'];
    if (db_column_exists('quiz_attempts', 'graded_by')) foreach (qa("SELECT graded_by, COUNT(*) n FROM quiz_attempts WHERE graded_by IN ($tin) AND graded_at BETWEEN ? AND ? GROUP BY graded_by", [$fromDt, $toDt]) as $r) $teachers[(int)$r['graded_by']]['graded'] = (int)$r['n'];

    /* ---------- Students ---------- */
    $allSids = [];
    foreach ($teachers as $t) foreach ($t['students'] as $sid => $_) $allSids[$sid] = true;
    $sids = array_keys($allSids) ?: [0];
    $sin = implode(',', $sids);
    $hasDemo = db_column_exists('users', 'gender');
    $S = [];
    foreach (qa('SELECT id, last_login' . ($hasDemo ? ', gender, special_needs, mother_tongue' : ', NULL gender, NULL special_needs, NULL mother_tongue') . " FROM users WHERE id IN ($sin)") as $r) {
        $S[(int)$r['id']] = $r + ['opened' => false, 'exercised' => false, 'secs' => 0, 'days' => 0, 'sess' => 0, 'pages' => 0, 'att' => 0, 'bs' => 0.0, 'bn' => 0, 'prog' => [], 'a11y' => []];
    }
    foreach (qa("SELECT DISTINCT student_id FROM page_views WHERE student_id IN ($sin)") as $r) $S[(int)$r['student_id']]['opened'] = true;
    foreach (qa("SELECT DISTINCT student_id FROM quiz_attempts WHERE student_id IN ($sin)") as $r) $S[(int)$r['student_id']]['exercised'] = true;
    foreach (qa("SELECT student_id, SUM(seconds) s, COUNT(DISTINCT day) d FROM usage_daily WHERE student_id IN ($sin) AND day BETWEEN ? AND ? GROUP BY student_id", [$from, $to]) as $r) { $S[(int)$r['student_id']]['secs'] = (int)$r['s']; $S[(int)$r['student_id']]['days'] = (int)$r['d']; }
    if ($hasSessions) foreach (qa("SELECT student_id, COUNT(*) n FROM learning_sessions WHERE student_id IN ($sin) AND started_at BETWEEN ? AND ? GROUP BY student_id", [$fromDt, $toDt]) as $r) $S[(int)$r['student_id']]['sess'] = (int)$r['n'];
    foreach (qa("SELECT student_id, COUNT(*) n FROM page_views WHERE student_id IN ($sin) AND last_seen BETWEEN ? AND ? GROUP BY student_id", [$fromDt, $toDt]) as $r) $S[(int)$r['student_id']]['pages'] = (int)$r['n'];
    foreach (qa("SELECT student_id, COUNT(*) n FROM quiz_attempts WHERE student_id IN ($sin) AND created_at BETWEEN ? AND ? GROUP BY student_id", [$fromDt, $toDt]) as $r) $S[(int)$r['student_id']]['att'] = (int)$r['n'];
    foreach (qa("SELECT student_id, SUM(best) b, COUNT(*) n FROM (SELECT student_id, book_id, section_id, MAX(score_pct) best FROM quiz_attempts WHERE student_id IN ($sin) AND created_at BETWEEN ? AND ? AND score_pct IS NOT NULL GROUP BY student_id, book_id, section_id) x GROUP BY student_id", [$fromDt, $toDt]) as $r) { $S[(int)$r['student_id']]['bs'] = (float)$r['b']; $S[(int)$r['student_id']]['bn'] = (int)$r['n']; }
    foreach ($classStudents as $cid => $list) foreach ($classBooks[$cid] ?? [] as $bid) foreach (progress_for($list, $bid) as $sid => $p) if (isset($S[$sid])) $S[$sid]['prog'][$bid] = $p['progress'];
    if ($hasEvents) foreach (qa("SELECT student_id, feature, COUNT(*) n FROM interaction_events WHERE student_id IN ($sin) AND created_at BETWEEN ? AND ? AND feature IN ('" . implode("','", a11y_features()) . "') GROUP BY student_id, feature", [$fromDt, $toDt]) as $r) $S[(int)$r['student_id']]['a11y'][$r['feature']] = (int)$r['n'];
    foreach ($S as &$s) { $s['avgprog'] = $s['prog'] ? array_sum($s['prog']) / count($s['prog']) : 0.0; $s['active'] = $s['secs'] > 0 || $s['att'] > 0; $s['cert'] = $s['prog'] && max($s['prog']) >= $th; } unset($s);

    $aggS = function (array $ids) use ($S): array {
        $a = ['n' => 0, 'logged' => 0, 'opened' => 0, 'exercised' => 0, 'active' => 0, 'prog25' => 0, 'secs' => 0, 'sess' => 0, 'pages' => 0, 'att' => 0, 'bs' => 0.0, 'bn' => 0,
            'prog' => 0.0, 'girls' => 0, 'boys' => 0, 'sen' => 0, 'a11y' => 0, 'certs' => 0];
        foreach ($ids as $id) {
            if (!isset($S[$id])) continue; $s = $S[$id];
            $a['n']++; $a['logged'] += ($s['last_login'] || $s['opened'] || $s['exercised']) ? 1 : 0; $a['opened'] += $s['opened'] ? 1 : 0; $a['exercised'] += $s['exercised'] ? 1 : 0;
            $a['active'] += $s['active'] ? 1 : 0; $a['prog25'] += $s['avgprog'] >= 25 ? 1 : 0;
            $a['secs'] += $s['secs']; $a['sess'] += $s['sess']; $a['pages'] += $s['pages']; $a['att'] += $s['att']; $a['bs'] += $s['bs']; $a['bn'] += $s['bn']; $a['prog'] += $s['avgprog'];
            $a['girls'] += $s['gender'] === 'F' ? 1 : 0; $a['boys'] += $s['gender'] === 'M' ? 1 : 0; $a['sen'] += (int)$s['special_needs'] === 1 ? 1 : 0;
            $a['a11y'] += $s['a11y'] ? 1 : 0; $a['certs'] += $s['cert'] ? 1 : 0;
        }
        $a['acc'] = $a['bn'] ? $a['bs'] / $a['bn'] : null;
        $a['avgprog'] = $a['n'] ? $a['prog'] / $a['n'] : 0.0;
        $a['hours'] = $a['secs'] / 3600;
        $a['min_per_active'] = $a['active'] ? $a['secs'] / 60 / $a['active'] : 0.0;
        $a['active_pct'] = $a['n'] ? 100 * $a['active'] / $a['n'] : 0.0;
        return $a;
    };

    /* ---------- Teacher stages ---------- */
    foreach ($teachers as $id => &$t) {
        $t['agg'] = $aggS(array_keys($t['students']));
        $n = $t['agg']['n']; $act = $t['agg']['active_pct'];
        $t['stage'] = !$t['classes'] || !$n ? 'Not started' : (!$t['books'] ? 'Onboarding' : ($act >= 70 && $t['agg']['avgprog'] >= 25 ? 'Champion' : ($act >= 30 ? 'Active' : 'Onboarding')));
        $t['days_to_class'] = ($t['training_date'] && $t['first_class']) ? max(0, (int)floor((strtotime(substr($t['first_class'], 0, 10)) - strtotime($t['training_date'])) / 86400)) : null;
    }
    unset($t);
    $trained = array_filter($teachers, fn($t) => $t['trained']);
    $untrained = array_filter($teachers, fn($t) => !$t['trained']);
    $idsOf = function (array $ts): array { $o = []; foreach ($ts as $t) foreach ($t['students'] as $sid => $_) $o[$sid] = true; return array_keys($o); };
    $trainedSids = $idsOf($trained);
    $ST = $aggS($trainedSids);
    $SU = $aggS($idsOf($untrained));

    /* ---------- Training KPIs ---------- */
    $reg = count(array_unique(array_map(fn($a) => (int)$a['teacher_id'], $att)));
    $attended = count($trained);
    $completed = count(array_unique(array_map(fn($a) => (int)$a['teacher_id'], array_filter($att, fn($a) => $a['status'] === 'completed'))));
    $pairs = array_filter($att, fn($a) => $a['pre_score'] !== null && $a['post_score'] !== null);
    $pre = $pairs ? array_sum(array_column($pairs, 'pre_score')) / count($pairs) : null;
    $post = $pairs ? array_sum(array_column($pairs, 'post_score')) / count($pairs) : null;
    $satL = array_filter(array_column($att, 'satisfaction'), fn($v) => $v !== null);
    $sat = $satL ? array_sum($satL) / count($satL) : null;
    $hoursDelivered = array_sum(array_map(fn($s) => (float)$s['hours'], $sessions));
    $participantHours = array_sum(array_map(fn($t) => $t['training_hours'], $trained));
    $certs = count(array_filter($trained, fn($t) => $t['cert']));
    $stages = ['Not started' => 0, 'Onboarding' => 0, 'Active' => 0, 'Champion' => 0];
    foreach ($trained as $t) $stages[$t['stage']]++;
    $dtc = array_filter(array_column($trained, 'days_to_class'), fn($v) => $v !== null);

    $teacherFunnel = [
        ['Trained (attended)', $attended],
        ['Logged in to the platform', count(array_filter($trained, fn($t) => $t['last_login']))],
        ['Created a class', count(array_filter($trained, fn($t) => $t['classes'] > 0))],
        ['Enrolled students', count(array_filter($trained, fn($t) => $t['agg']['n'] > 0))],
        ['Assigned an ADT book', count(array_filter($trained, fn($t) => $t['books'] > 0))],
        ['Have active students', count(array_filter($trained, fn($t) => $t['agg']['active'] > 0))],
        ['Champions (≥70% active, ≥25% progress)', $stages['Champion']],
    ];
    $studentFunnel = [
        ['Enrolled by trained teachers', $ST['n']],
        ['Logged in at least once', $ST['logged']],
        ['Opened an ADT book', $ST['opened']],
        ['Submitted a first exercise', $ST['exercised']],
        ['Active in the reporting period', $ST['active']],
        ['Reached ≥25% book progress', $ST['prog25']],
    ];

    /* ---------- By municipality ---------- */
    $byMun = [];
    foreach ($trained as $t) {
        $m = $t['school_city'] ?: ($t['municipality'] ?: 'Not specified');
        $byMun[$m]['teachers'][] = $t;
    }
    ksort($byMun);
    $munRows = [];
    foreach ($byMun as $m => $g) {
        $a = $aggS($idsOf($g['teachers']));
        $munRows[] = [$m, count($g['teachers']), $a['n'], $a['girls'], $a['boys'], $a['sen'], $a['active'], round($a['active_pct']), round($a['hours'], 1), round($a['avgprog']), $a['acc'] !== null ? round($a['acc']) : null];
    }

    /* ---------- Weekly trend (students of trained teachers) ---------- */
    $weekly = [];
    if ($trainedSids) {
        $in = implode(',', $trainedSids);
        foreach (qa("SELECT YEARWEEK(day, 3) yw, MIN(day) d, SUM(seconds) s, COUNT(DISTINCT student_id) n FROM usage_daily WHERE student_id IN ($in) AND day BETWEEN ? AND ? GROUP BY yw ORDER BY yw", [$from, $to]) as $r) {
            $weekly[] = ['label' => date('d M', strtotime($r['d'])), 'minutes' => (int)round((int)$r['s'] / 60), 'students' => (int)$r['n']];
        }
    }

    /* ---------- Accessibility ---------- */
    $a11yRows = [];
    if ($hasEvents) {
        $cat = feature_catalog();
        $enMap = ['tts' => 'Read-aloud (text-to-speech)', 'sign_language' => 'Sign language videos', 'easy_read' => 'Easy-read text', 'eli5' => 'Explain-it-to-me (text expansion)',
            'syllables' => 'Syllable splitting', 'glossary' => 'Glossary', 'describe_images' => 'Image descriptions', 'highlight' => 'Text highlighting', 'language' => 'Language switch',
            'display' => 'Display (theme, size, motion)', 'screen_reader' => 'Keyboard / screen-reader navigation', 'portal_a11y' => 'Portal accessibility bar (A+, contrast, read)'];
        foreach (a11y_features() as $f) {
            $uses = 0; $users = 0; $sen = 0;
            foreach ($trainedSids as $sid) if (!empty($S[$sid]['a11y'][$f])) { $uses += $S[$sid]['a11y'][$f]; $users++; if ((int)$S[$sid]['special_needs'] === 1) $sen++; }
            if ($uses) $a11yRows[] = [$enMap[$f] ?? ($cat[$f][1] ?? $f), $uses, $users, $sen];
        }
        usort($a11yRows, fn($a, $b) => $b[1] <=> $a[1]);
    }

    /* ---------- Hardest exercises ---------- */
    $hard = [];
    if ($trainedSids) {
        $in = implode(',', $trainedSids);
        foreach (qa("SELECT b.title, bp.page_number, bp.title ptitle, COUNT(DISTINCT q.student_id) st, AVG(q.score_pct) acc FROM quiz_attempts q JOIN books b ON b.id=q.book_id
            JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id WHERE q.student_id IN ($in) AND q.created_at BETWEEN ? AND ? AND q.score_pct IS NOT NULL
            GROUP BY q.book_id, q.section_id HAVING st >= 2 ORDER BY acc ASC LIMIT 5", [$fromDt, $toDt]) as $r) {
            $hard[] = [$r['title'], $r['page_number'] ?: '—', mb_strimwidth((string)$r['ptitle'], 0, 60, '…'), (int)$r['st'], round((float)$r['acc'])];
        }
    }

    /* ---------- Support tickets ---------- */
    $tickets = [];
    $tIds = implode(',', array_merge(array_keys($trained) ?: [0], $trainedSids ?: [0]));
    $catsEn = ['not_working' => 'Something does not work', 'dont_understand' => 'Did not understand the task', 'login' => 'Login problem', 'content' => 'Error in the book',
        'technical' => 'Technical problem', 'account' => 'Account / access', 'other' => 'Other'];
    foreach (qa("SELECT category, COUNT(*) n, SUM(status='closed') closed FROM tickets WHERE created_by IN ($tIds) AND created_at BETWEEN ? AND ? GROUP BY category ORDER BY n DESC", [$fromDt, $toDt]) as $r) {
        $tickets[] = [$catsEn[$r['category']] ?? $r['category'], (int)$r['n'], (int)$r['closed']];
    }

    /* ---------- Narrative ---------- */
    $pctS = fn($a, $b) => $b ? (int)round(100 * $a / $b) : 0;
    $nTeachers = count($teachers);
    $summary = [];
    $summary[] = sprintf('Between **%s** and **%s**, **%d** training session%s (%s hours in total) were delivered. **%d** of %d registered teachers attended (%d%%) and **%d** completed the full programme%s.',
        date('j F Y', strtotime($from)), date('j F Y', strtotime($to)), count($sessions), count($sessions) === 1 ? '' : 's', rtrim(rtrim(number_format($hoursDelivered, 1), '0'), '.'),
        $attended, $reg, $pctS($attended, $reg), $completed, $pre !== null ? sprintf('; average test scores rose from **%d%%** to **%d%%** (+%d points)', round($pre), round($post), round($post - $pre)) : '');
    $summary[] = sprintf('After training, **%d%%** of trained teachers set up a class on the platform and **%d%%** assigned at least one ADT Studio textbook to their students. %d teacher%s already qualify as **champions**, with most of their class active and progressing.',
        $pctS($teacherFunnel[2][1], $attended), $pctS($teacherFunnel[4][1], $attended), $stages['Champion'], $stages['Champion'] === 1 ? '' : 's');
    $summary[] = sprintf('Trained teachers enrolled **%d students** (%d girls, %d boys%s). **%d%%** of them logged in, **%d%%** opened an ADT book and **%d%%** were active during the period, spending **%s hours** reading and completing **%s exercises** with an average accuracy of **%s**.',
        $ST['n'], $ST['girls'], $ST['boys'], $ST['sen'] ? ", including " . small_cell($ST['sen']) . " with special educational needs" : '', $pctS($ST['logged'], $ST['n']), $pctS($ST['opened'], $ST['n']), $pctS($ST['active'], $ST['n']),
        number_format($ST['hours'], 1), number_format($ST['att']), $ST['acc'] !== null ? round($ST['acc']) . '%' : 'n/a');
    if ($SU['n'] && $ST['n']) {
        $summary[] = sprintf('Classes of trained teachers show **%d%%** active students versus **%d%%** in classes of teachers not yet trained, and an average book progress of **%d%%** versus **%d%%**.',
            round($ST['active_pct']), round($SU['active_pct']), round($ST['avgprog']), round($SU['avgprog']));
    }

    $recs = [];
    if ($stages['Not started'] + $stages['Onboarding'] > 0) $recs[] = sprintf('Provide follow-up coaching to the **%d trained teacher%s** who have not yet activated their class (listed in Annex B).', $stages['Not started'] + $stages['Onboarding'], ($stages['Not started'] + $stages['Onboarding']) === 1 ? '' : 's');
    if ($ST['n'] && $pctS($ST['logged'], $ST['n']) < 80) $recs[] = 'Support first log-in in class (printed login cards, picture passwords) so that every enrolled student starts using the books.';
    if ($ST['acc'] !== null && $ST['acc'] < 70) $recs[] = 'Review the hardest exercises with teachers and add guided practice for those pages.';
    if ($ST['sen'] && !$a11yRows) $recs[] = 'Enable and demonstrate the ADT accessibility features (read-aloud, easy-read, sign language) in the book exports, so that students with special educational needs can benefit.';
    if ($reg - $attended > 0) $recs[] = sprintf('Offer a make-up session for the **%d registered teacher%s** who did not attend.', $reg - $attended, ($reg - $attended) === 1 ? '' : 's');
    if ($nTeachers - $reg > 0) $recs[] = sprintf('Extend training to the **%d teacher%s** on the platform who are not yet registered for any session.', $nTeachers - $reg, ($nTeachers - $reg) === 1 ? '' : 's');
    $recs[] = 'Continue monthly monitoring with the platform reports and share monthly progress reports with parents.';
    foreach (preg_split('/\r?\n/', trim((string)($o['recs_extra'] ?? ''))) as $l) if (trim($l) !== '') $recs[] = trim(ltrim(trim($l), '-•* '));

    return compact('o', 'from', 'to', 'sessions', 'teachers', 'trained', 'untrained', 'ST', 'SU', 'reg', 'attended', 'completed', 'pre', 'post', 'sat', 'hoursDelivered',
        'participantHours', 'certs', 'stages', 'dtc', 'teacherFunnel', 'studentFunnel', 'munRows', 'weekly', 'a11yRows', 'hard', 'tickets', 'summary', 'recs', 'nTeachers', 'hasEvents');
}

/* ======================= Përbërësit e përbashkët ======================= */
function tr_fmt($v, int $dec = 0): string { return $v === null ? '—' : number_format((float)$v, $dec, '.', ','); }

/** Të gjitha tabelat e raportit, në një vend (për HTML, Word dhe Excel) */
function training_report_tables(array $R): array {
    $T = [];
    $T['sessions'] = ['title' => 'Training sessions', 'cols' => [['Session', 'left', 30], ['Date', 'left', 12], ['Municipality', 'left', 13], ['Hours', 'right', 7], ['Registered', 'right', 9], ['Attended', 'right', 9], ['Completed', 'right', 9], ['Pre → post test', 'right', 11]],
        'rows' => array_map(fn($s) => [$s['title'], date('d.m.Y', strtotime($s['start_date'])), $s['municipality'] ?: '—', tr_fmt((float)$s['hours'], 1), (int)$s['registered'], (int)$s['attended'], (int)$s['completed'],
            $s['pre'] !== null && $s['post'] !== null ? round((float)$s['pre']) . '% → ' . round((float)$s['post']) . '%' : '—'], $R['sessions'])];
    $T['stages'] = ['title' => 'Adoption stage of trained teachers', 'cols' => [['Stage', 'left', 25], ['Definition', 'left', 55], ['Teachers', 'right', 10], ['Share', 'right', 10]], 'rows' => []];
    $def = ['Not started' => 'No class or no students on the platform yet', 'Onboarding' => 'Students enrolled but book not assigned or <30% active', 'Active' => '≥30% of students active in the period', 'Champion' => '≥70% of students active and ≥25% average book progress'];
    foreach ($R['stages'] as $k => $n) $T['stages']['rows'][] = [$k, $def[$k], $n, ($R['attended'] ? round(100 * $n / $R['attended']) : 0) . '%'];
    $T['inclusion'] = ['title' => 'Student inclusion', 'cols' => [['Group', 'left', 40], ['Students', 'right', 15], ['Share', 'right', 15], ['Active', 'right', 15], ['Active %', 'right', 15]], 'rows' => [], 'total_last' => true];
    $ST = $R['ST'];
    foreach ([['Girls', $ST['girls']], ['Boys', $ST['boys']], ['Gender not recorded', $ST['n'] - $ST['girls'] - $ST['boys']], ['Students with special educational needs', $ST['sen']]] as [$l, $n]) {
        if ($l === 'Gender not recorded' && $n <= 0) continue;
        $sm = small_cell($n) !== $n;
        $T['inclusion']['rows'][] = [$l, small_cell($n), $sm ? '—' : ($ST['n'] ? round(100 * $n / $ST['n']) : 0) . '%', '', ''];
    }
    $T['inclusion']['rows'][] = ['All students of trained teachers', $ST['n'], '100%', $ST['active'], round($ST['active_pct']) . '%'];
    $T['progress'] = ['title' => 'Learning with ADT Studio books (students of trained teachers)', 'cols' => [['Indicator', 'left', 60], ['Value', 'right', 40]], 'rows' => [
        ['Active students in the period', $ST['active'] . ' of ' . $ST['n'] . ' (' . round($ST['active_pct']) . '%)'],
        ['Total learning time', tr_fmt($ST['hours'], 1) . ' hours'],
        ['Average time per active student', tr_fmt($ST['min_per_active']) . ' minutes'],
        ['Reading sessions', tr_fmt($ST['sess'])],
        ['Book pages read', tr_fmt($ST['pages'])],
        ['Exercise attempts', tr_fmt($ST['att'])],
        ['Average exercise accuracy (best attempt)', $ST['acc'] !== null ? round($ST['acc']) . '%' : '—'],
        ['Average book progress', round($ST['avgprog']) . '%'],
        ['Students who earned a completion certificate', tr_fmt($ST['certs'])],
        ['Students using accessibility features', $ST['a11y'] . ($ST['active'] ? ' (' . round(100 * $ST['a11y'] / $ST['active']) . '% of active)' : '')],
    ]];
    $T['municipality'] = ['title' => 'Results by municipality', 'cols' => [['Municipality', 'left', 16], ['Trained teachers', 'right', 9], ['Students', 'right', 8], ['Girls', 'right', 7], ['Boys', 'right', 7], ['SEN', 'right', 6], ['Active', 'right', 8], ['Active %', 'right', 8], ['Hours', 'right', 8], ['Avg. progress %', 'right', 11], ['Accuracy %', 'right', 12]],
        'rows' => array_map(function ($r) { $r = array_map(fn($v) => $v === null ? '—' : $v, $r); foreach ([2, 3, 4, 5, 6] as $i) if (isset($r[$i])) $r[$i] = small_cell($r[$i]); return $r; }, $R['munRows'])];
    $SU = $R['SU'];
    $T['compare'] = ['title' => 'Trained vs. not-yet-trained teachers', 'cols' => [['Indicator', 'left', 46], ['Trained teachers', 'right', 27], ['Not yet trained', 'right', 27]], 'rows' => [
        ['Teachers', count($R['trained']), count($R['untrained'])],
        ['Students enrolled', $ST['n'], $SU['n']],
        ['Active students %', round($ST['active_pct']) . '%', $SU['n'] ? round($SU['active_pct']) . '%' : '—'],
        ['Minutes per active student', tr_fmt($ST['min_per_active']), $SU['active'] ? tr_fmt($SU['min_per_active']) : '—'],
        ['Average book progress', round($ST['avgprog']) . '%', $SU['n'] ? round($SU['avgprog']) . '%' : '—'],
        ['Exercise accuracy', $ST['acc'] !== null ? round($ST['acc']) . '%' : '—', $SU['acc'] !== null ? round($SU['acc']) . '%' : '—'],
    ]];
    $T['a11y'] = ['title' => 'Use of ADT accessibility features', 'cols' => [['Feature', 'left', 52], ['Uses', 'right', 16], ['Students', 'right', 16], ['…with SEN', 'right', 16]], 'rows' => array_map(function ($r) { $r = array_values($r); foreach ([2, 3] as $i) if (isset($r[$i])) $r[$i] = small_cell($r[$i]); return $r; }, $R['a11yRows'])];
    $T['hard'] = ['title' => 'Exercises with the lowest accuracy', 'cols' => [['Book', 'left', 32], ['Page', 'right', 8], ['Exercise', 'left', 36], ['Students', 'right', 12], ['Accuracy %', 'right', 12]], 'rows' => $R['hard']];
    $T['tickets'] = ['title' => 'Support requests raised', 'cols' => [['Category', 'left', 60], ['Requests', 'right', 20], ['Resolved', 'right', 20]], 'rows' => $R['tickets']];
    $trainedList = $R['trained'];
    uasort($trainedList, fn($a, $b) => [$a['school_city'], $a['full_name']] <=> [$b['school_city'], $b['full_name']]);
    $T['teachers'] = ['title' => 'Annex A — Trained teachers and their classes', 'cols' => [['Teacher', 'left', 18], ['Municipality', 'left', 11], ['School', 'left', 17], ['Trained', 'left', 10], ['Classes', 'right', 7], ['Students', 'right', 8], ['Active %', 'right', 7], ['Hours', 'right', 7], ['Progress %', 'right', 7], ['Stage', 'left', 8]],
        'rows' => array_values(array_map(fn($t) => [$t['full_name'], $t['school_city'] ?: ($t['municipality'] ?: '—'), $t['school'] ?: '—', $t['training_date'] ? date('d.m.y', strtotime($t['training_date'])) : '—',
            $t['classes'], $t['agg']['n'], round($t['agg']['active_pct']), tr_fmt($t['agg']['hours'], 1), round($t['agg']['avgprog']), $t['stage']], $trainedList))];
    $follow = array_filter($trainedList, fn($t) => in_array($t['stage'], ['Not started', 'Onboarding'], true));
    $T['follow'] = ['title' => 'Annex B — Teachers recommended for follow-up support', 'cols' => [['Teacher', 'left', 25], ['School', 'left', 25], ['Municipality', 'left', 15], ['Stage', 'left', 12], ['What is missing', 'left', 23]],
        'rows' => array_values(array_map(fn($t) => [$t['full_name'], $t['school'] ?: '—', $t['school_city'] ?: '—', $t['stage'],
            !$t['classes'] ? 'Create a class' : (!$t['agg']['n'] ? 'Enrol students' : (!$t['books'] ? 'Assign an ADT book' : 'Activate students'))], $follow))];
    return $T;
}

function training_report_kpis(array $R): array {
    $ST = $R['ST'];
    return [
        ['Training sessions', count($R['sessions'])],
        ['Training hours delivered', tr_fmt($R['hoursDelivered'], 1)],
        ['Teachers trained', $R['attended'] . ' / ' . $R['reg'] . ' registered'],
        ['Test score gain', $R['pre'] !== null ? '+' . round($R['post'] - $R['pre']) . ' pts' : '—'],
        ['Teachers using ADT books', ($R['attended'] ? round(100 * $R['teacherFunnel'][4][1] / $R['attended']) : 0) . '%'],
        ['Students reached', tr_fmt($ST['n'])],
        ['Girls / boys', $ST['girls'] . ' / ' . $ST['boys']],
        ['Students with SEN', small_cell($ST['sen'])],
        ['Active students', round($ST['active_pct']) . '%'],
        ['Learning hours', tr_fmt($ST['hours'], 1)],
        ['Exercise accuracy', $ST['acc'] !== null ? round($ST['acc']) . '%' : '—'],
        ['Average book progress', round($ST['avgprog']) . '%'],
    ];
}

function tr_strip(string $s): string { return preg_replace('/\*\*(.+?)\*\*/u', '$1', $s); }
function tr_bold_html(string $s): string { return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($s)); }

/* ======================= HTML (pamja + printimi) ======================= */
function training_report_html(array $R): string {
    $o = $R['o']; $T = training_report_tables($R);
    $tbl = function (array $t) {
        if (!$t['rows']) return '<p class="muted">No data for this period.</p>';
        $h = '<div class="table-wrap"><table class="t report-t"><thead><tr>';
        foreach ($t['cols'] as $c) $h .= '<th class="' . (($c[1] ?? '') === 'right' ? 'num' : '') . '">' . e($c[0]) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($t['rows'] as $i => $r) {
            $h .= '<tr class="' . (!empty($t['total_last']) && $i === count($t['rows']) - 1 ? 'tot' : '') . '">';
            foreach (array_values($r) as $ci => $v) $h .= '<td class="' . (($t['cols'][$ci][1] ?? '') === 'right' ? 'num' : '') . '">' . e((string)$v) . '</td>';
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    };
    $funnel = function (array $rows, string $color) {
        $max = max(1, $rows[0][1]);
        $h = '<div class="hbars">';
        foreach ($rows as [$l, $v]) $h .= '<div class="hbar"><span>' . e($l) . '</span><div class="pbar" style="height:16px"><span style="width:' . min(100, round(100 * $v / $max)) . '%;background:' . $color . '"></span></div><span class="small"><strong>' . $v . '</strong> (' . round(100 * $v / $max) . '%)</span></div>';
        return $h . '</div>';
    };
    ob_start(); ?>
    <article class="report tr-doc" lang="en">
      <header class="report-head">
        <div><h1>Teacher Training and ADT Studio Adoption Report</h1>
          <p class="muted"><?= e($o['org'] ?: cfg('app_name')) ?> · Reporting period <?= e(date('j M Y', strtotime($R['from']))) ?> – <?= e(date('j M Y', strtotime($R['to']))) ?><?= $o['komuna'] ? ' · Municipality: ' . e($o['komuna']) : '' ?></p></div>
        <div class="small muted" style="text-align:right">Prepared by <?= e($o['author'] ?: '—') ?><br><?= e(date('j F Y')) ?></div>
      </header>
      <div class="report-kpis"><?php foreach (training_report_kpis($R) as [$l, $v]): ?><div class="rk"><div class="lbl"><?= e($l) ?></div><div class="val"><?= e((string)$v) ?></div></div><?php endforeach; ?></div>
      <section class="report-sec"><h2>1. Executive summary</h2><?php foreach ($R['summary'] as $p): ?><p><?= tr_bold_html($p) ?></p><?php endforeach; ?></section>
      <section class="report-sec"><h2>2. Background</h2><?php foreach (preg_split('/\r?\n\r?\n/', trim($o['background'])) as $p): ?><p><?= nl2br(e($p)) ?></p><?php endforeach; ?></section>
      <section class="report-sec"><h2>3. Teacher training</h2><?= $tbl($T['sessions']) ?>
        <p class="small muted">Participant-hours: <?= tr_fmt($R['participantHours'], 1) ?> · Average satisfaction: <?= $R['sat'] !== null ? tr_fmt($R['sat'], 1) . ' / 5' : '—' ?> · Training certificates issued: <?= $R['certs'] ?><?= $R['dtc'] ? ' · Median days from training to first class: ' . (function ($a) { sort($a); return $a[intdiv(count($a), 2)]; })($R['dtc']) : '' ?></p></section>
      <section class="report-sec"><h2>4. From training to classroom use</h2><?= $funnel($R['teacherFunnel'], '#2f5bea') ?><h3 class="mt">Adoption stage</h3><?= $tbl($T['stages']) ?></section>
      <section class="report-sec"><h2>5. Student inclusion</h2><?= $funnel($R['studentFunnel'], '#13a39a') ?><h3 class="mt">Who is included</h3><?= $tbl($T['inclusion']) ?><h3>By municipality</h3><?= $tbl($T['municipality']) ?></section>
      <section class="report-sec"><h2>6. Student progress with ADT Studio books</h2><?= $tbl($T['progress']) ?>
        <?php if ($R['weekly']): ?><h3>Weekly learning time</h3><?= bar_chart(array_map(fn($w) => ['label' => $w['label'], 'value' => $w['minutes']], $R['weekly']), 'min', 'Weekly learning minutes') ?><?php endif; ?>
        <?php if ($T['a11y']['rows']): ?><h3>Accessibility features</h3><?= $tbl($T['a11y']) ?><?php endif; ?>
        <?php if ($T['hard']['rows']): ?><h3>Exercises needing attention</h3><?= $tbl($T['hard']) ?><?php endif; ?></section>
      <?php if ($R['untrained']): ?><section class="report-sec"><h2>7. Effect of training</h2><?= $tbl($T['compare']) ?><p class="small muted">Descriptive comparison only; differences may also reflect school context and timing.</p></section><?php endif; ?>
      <section class="report-sec"><h2><?= $R['untrained'] ? '8' : '7' ?>. Support and challenges</h2><?= $tbl($T['tickets']) ?></section>
      <section class="report-sec"><h2><?= $R['untrained'] ? '9' : '8' ?>. Recommendations</h2><ul><?php foreach ($R['recs'] as $r): ?><li><?= tr_bold_html($r) ?></li><?php endforeach; ?></ul></section>
      <section class="report-sec"><h2>Annex A — Trained teachers</h2><?= $tbl($T['teachers']) ?></section>
      <section class="report-sec"><h2>Annex B — Follow-up list</h2><?= $tbl($T['follow']) ?></section>
      <section class="report-sec"><h2>Annex C — Method and data protection</h2><?= training_report_method_html() ?></section>
    </article>
    <?php return (string)ob_get_clean();
}

function training_report_method_lines(): array {
    return [
        'Data come directly from the platform database at the time the report was generated: training attendance recorded by the programme team, and students\' use of the ADT Studio books recorded automatically.',
        '"Trained" teachers attended or completed at least one session. "Active" students read or submitted exercises during the reporting period. Learning time counts only active reading (it pauses after 2 minutes of inactivity).',
        'Accuracy is the average of each student\'s best attempt on automatically marked exercises and teacher-graded open answers. Book progress = 60% pages read + 40% exercises submitted.',
        'Student data are reported in aggregate only. Special educational needs data are stored only with written parental consent and are never shown at individual level in this report.',
    ];
}
function training_report_method_html(): string {
    return '<ul>' . implode('', array_map(fn($l) => '<li>' . e($l) . '</li>', training_report_method_lines())) . '</ul>';
}

/* ======================= Word (.docx) ======================= */
function training_report_docx(array $R, string $filename): never {
    $o = $R['o']; $T = training_report_tables($R);
    $d = new DocxWriter();
    $d->footer(($o['org'] ?: cfg('app_name')) . ' · Teacher Training and ADT Studio Adoption Report');
    $d->title('Teacher Training and ADT Studio Adoption Report');
    $d->subtitle(($o['org'] ?: cfg('app_name')));
    $d->p('Reporting period: **' . date('j F Y', strtotime($R['from'])) . ' – ' . date('j F Y', strtotime($R['to'])) . '**' . ($o['komuna'] ? '  ·  Municipality: **' . $o['komuna'] . '**' : ''));
    $d->p('Prepared by: ' . ($o['author'] ?: '—') . '  ·  Date: ' . date('j F Y'));
    $d->p('');
    $d->kpis(training_report_kpis($R), 3);
    $d->h1('1. Executive summary', true);
    foreach ($R['summary'] as $p) $d->p($p);
    $d->h1('2. Background');
    foreach (preg_split('/\r?\n\r?\n/', trim($o['background'])) as $p) $d->p(trim($p));
    $d->h1('3. Teacher training');
    $d->p(sprintf('%d session%s delivered %s training hours (%s participant-hours). Average satisfaction %s; %d training certificate%s issued.',
        count($R['sessions']), count($R['sessions']) === 1 ? '' : 's', tr_fmt($R['hoursDelivered'], 1), tr_fmt($R['participantHours'], 1),
        $R['sat'] !== null ? tr_fmt($R['sat'], 1) . ' / 5' : 'not recorded', $R['certs'], $R['certs'] === 1 ? '' : 's'));
    $T['sessions']['rows'] ? $d->table($T['sessions']['cols'], $T['sessions']['rows']) : $d->note('No training sessions recorded for this period.');
    $d->h1('4. From training to classroom use');
    $d->p('The chart follows trained teachers from the training room to regular classroom use of the ADT Studio textbooks on the platform.');
    $d->image(chart_hbars($R['teacherFunnel'], 'Teacher adoption funnel', '#2f5bea', max(1, (float)$R['teacherFunnel'][0][1])), 16.5, 'Figure 1 — Trained teachers at each step of platform adoption');
    $d->h2('Adoption stage');
    $d->table($T['stages']['cols'], $T['stages']['rows']);
    $d->h1('5. Student inclusion');
    $d->p('Students are included through their teachers: teachers enrol their class, distribute login cards (username or picture password) and assign the ADT books.');
    $d->image(chart_hbars($R['studentFunnel'], 'Student onboarding', '#13a39a', max(1, (float)$R['studentFunnel'][0][1])), 16.5, 'Figure 2 — Students of trained teachers at each onboarding step');
    $d->h2('Who is included');
    $d->table($T['inclusion']['cols'], $T['inclusion']['rows'], ['total_last' => true]);
    if ($T['municipality']['rows']) { $d->h2('By municipality'); $d->table($T['municipality']['cols'], $T['municipality']['rows']); }
    $d->h1('6. Student progress with ADT Studio books');
    $d->table($T['progress']['cols'], $T['progress']['rows']);
    if ($R['weekly']) $d->image(chart_vbars(array_map(fn($w) => [$w['label'], $w['minutes']], $R['weekly']), 'Weekly learning time (minutes)', 'Week starting'), 16.5, 'Figure 3 — Minutes spent learning with ADT books each week');
    if ($T['a11y']['rows']) { $d->h2('Use of accessibility features'); $d->table($T['a11y']['cols'], $T['a11y']['rows']); }
    if ($T['hard']['rows']) { $d->h2('Exercises needing attention'); $d->table($T['hard']['cols'], $T['hard']['rows']); }
    $n = 7;
    if ($R['untrained']) {
        $d->h1(($n++) . '. Effect of training');
        $d->table($T['compare']['cols'], $T['compare']['rows']);
        $d->note('Descriptive comparison only; differences may also reflect school context and timing.');
    }
    $d->h1(($n++) . '. Support and challenges');
    $T['tickets']['rows'] ? $d->table($T['tickets']['cols'], $T['tickets']['rows']) : $d->p('No support requests were raised by trained teachers or their students in this period.');
    $d->h1(($n++) . '. Recommendations');
    $d->bullets($R['recs']);
    $d->h1('Annex A — Trained teachers and their classes', true);
    $T['teachers']['rows'] ? $d->table($T['teachers']['cols'], $T['teachers']['rows']) : $d->note('No trained teachers in this selection.');
    $d->h1('Annex B — Teachers recommended for follow-up');
    $T['follow']['rows'] ? $d->table($T['follow']['cols'], $T['follow']['rows']) : $d->p('All trained teachers are actively using the platform.');
    $d->h1('Annex C — Method and data protection');
    $d->bullets(training_report_method_lines());
    $d->send($filename);
}

/* ======================= Excel (tabelat) ======================= */
function training_report_xlsx(array $R, string $filename): never {
    $T = training_report_tables($R);
    $sum = [['Teacher Training and ADT Studio Adoption Report'], ['Period ' . $R['from'] . ' – ' . $R['to']], [], ['Indicator', 'Value']];
    foreach (training_report_kpis($R) as [$l, $v]) $sum[] = [$l, is_numeric($v) ? $v + 0 : (string)$v];
    $sum[] = []; $sum[] = ['Executive summary'];
    foreach ($R['summary'] as $p) $sum[] = [tr_strip($p)];
    $sheets = [['name' => 'Summary', 'rows' => $sum, 'title_rows' => [0], 'bold_rows' => [3], 'widths' => [60, 30]]];
    $add = function (string $name, array $t, array $extra = []) use (&$sheets) {
        $rows = [[$t['title']], [], array_map(fn($c) => $c[0], $t['cols'])];
        foreach ($t['rows'] as $r) $rows[] = array_map(fn($v) => is_numeric($v) && !is_string($v) ? $v : (string)$v, array_values($r));
        $sheets[] = ['name' => $name, 'rows' => $rows, 'title_rows' => [0], 'bold_rows' => [2], 'widths' => array_map(fn($c) => ($c[1] ?? '') === 'right' ? 13 : 30, $t['cols'])];
    };
    $add('Training sessions', $T['sessions']);
    $funnel = ['title' => 'Adoption funnels', 'cols' => [['Step', 'left'], ['Count', 'right']], 'rows' => array_merge([['TEACHERS', '']], $R['teacherFunnel'], [['', ''], ['STUDENTS', '']], $R['studentFunnel'])];
    $add('Funnels', $funnel);
    $add('Teacher stages', $T['stages']);
    $add('Student inclusion', $T['inclusion']);
    $add('By municipality', $T['municipality']);
    $add('Student progress', $T['progress']);
    if ($R['weekly']) $add('Weekly', ['title' => 'Weekly learning', 'cols' => [['Week starting', 'left'], ['Minutes', 'right'], ['Active students', 'right']], 'rows' => array_map(fn($w) => [$w['label'], $w['minutes'], $w['students']], $R['weekly'])]);
    $add('Trained vs untrained', $T['compare']);
    $add('Accessibility', $T['a11y']);
    $add('Hardest exercises', $T['hard']);
    $add('Support requests', $T['tickets']);
    $add('Annex A teachers', $T['teachers']);
    $add('Annex B follow-up', $T['follow']);
    xlsx_send($filename, $sheets);
}
