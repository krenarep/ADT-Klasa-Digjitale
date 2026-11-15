<?php
declare(strict_types=1);

/**
 * Vlerësimi dhe verifikimi nga mësuesi (human-in-the-loop).
 * Çdo dërgesë e nxënësit ruhet (tentativat, përgjigjet, rezultati automatik i librit).
 * Rezultati automatik është vetëm propozim: mësuesi e KONFIRMON ose e NDRYSHON, dhe mund të lërë koment.
 * Përgjigjet e hapura (pa përgjigje të saktë) i vlerëson gjithmonë mësuesi.
 */

/** Ruajtja e vlerësimit / verifikimit për një tentativë */
function handle_grade_post(array $u): void {
    $aid = posti('attempt');
    $att = q1('SELECT q.*, s.full_name FROM quiz_attempts q JOIN users s ON s.id=q.student_id WHERE q.id=?', [$aid]);
    if (!$att || ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], (int)$att['student_id']))) { flash('err', 'Tentativa nuk u gjet.'); return; }
    $note = mb_substr(trim((string)post('note')), 0, 500) ?: null;
    $score = post('score');
    $decision = (string)post('decision');            // confirm | adjust (bosh = sipas zgjedhjes)
    $auto = $att['auto_score'] ?? ($att['graded_by'] === null ? $att['score_pct'] : null);

    if ($decision === 'confirm' && $auto !== null) {
        q('UPDATE quiz_attempts SET score_pct=?, teacher_note=?, graded_by=NULL, graded_at=NULL, verified_by=?, verified_at=NOW(), student_seen=0 WHERE id=?', [(int)$auto, $note, $u['id'], $aid]);
        $msg = '✔ U konfirmua rezultati ' . (int)$auto . '%.';
        audit('grade', $att['full_name'], "Tentativa #$aid · konfirmuar $auto%");
    } elseif ($score !== '' && is_numeric($score)) {
        $sc = max(0, min(100, (int)$score));
        q('UPDATE quiz_attempts SET score_pct=?, teacher_note=?, graded_by=?, graded_at=NOW(), verified_by=?, verified_at=NOW(), student_seen=0 WHERE id=?', [$sc, $note, $u['id'], $u['id'], $aid]);
        $msg = $auto !== null && $sc !== (int)$auto ? "✍️ Rezultati u ndryshua: $auto% → $sc%." : "✍️ Vlerësimi u ruajt ($sc%).";
        audit('grade', $att['full_name'], "Tentativa #$aid · $sc%" . ($auto !== null ? " (automatik $auto%)" : '') . ($note ? ' · ' . mb_substr($note, 0, 80) : ''));
    } elseif ($att['score_pct'] === null) {
        flash('err', 'Zgjidhni një vlerësim (0–100%) për përgjigjen e hapur.');
        return;
    } else {
        q('UPDATE quiz_attempts SET teacher_note=?, verified_by=COALESCE(verified_by, ?), verified_at=COALESCE(verified_at, NOW()), student_seen=0 WHERE id=?', [$note, $u['id'], $aid]);
        $msg = '💬 Komenti u ruajt.';
    }
    // tentativat e mëparshme të së njëjtës faqe nuk presin më verifikim (verifikohet e fundit)
    verify_older_attempts($att, (int)$u['id']);
    flash('ok', $msg . ($note ? ' Nxënësi e sheh komentin.' : ''));
}

function verify_older_attempts(array $att, int $uid): void {
    q('UPDATE quiz_attempts SET verified_by=?, verified_at=NOW() WHERE student_id=? AND book_id=? AND section_id=? AND id<? AND verified_at IS NULL',
        [$uid, $att['student_id'], $att['book_id'], $att['section_id'], $att['id']]);
}

/** Konfirmim në grup i rezultateve automatike (përgjigjet e hapura anashkalohen) */
function handle_bulk_verify(array $u, array $ids): int {
    $n = 0;
    foreach (array_slice(array_map('intval', $ids), 0, 300) as $aid) {
        $a = q1('SELECT * FROM quiz_attempts WHERE id=? AND verified_at IS NULL', [$aid]);
        if (!$a || $a['score_pct'] === null || ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], (int)$a['student_id']))) continue;
        q('UPDATE quiz_attempts SET verified_by=?, verified_at=NOW() WHERE id=?', [$u['id'], $aid]);
        verify_older_attempts($a, (int)$u['id']);
        $n++;
    }
    if ($n) audit('grade', $n . ' tentativa', 'Konfirmim në grup i rezultateve automatike');
    return $n;
}

/** Kushti SQL: tentativa e fundit e çdo nxënësi për çdo faqe që ende s'është verifikuar */
function pending_verify_sql(): string {
    return "q.verified_at IS NULL AND q.id = (SELECT MAX(q2.id) FROM quiz_attempts q2 WHERE q2.student_id=q.student_id AND q2.book_id=q.book_id AND q2.section_id=q.section_id)";
}

function pending_verify_count(int $teacherId): int {
    return (int)qv('SELECT COUNT(*) FROM quiz_attempts q WHERE ' . pending_verify_sql() . '
        AND q.student_id IN (SELECT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=?)', [$teacherId]);
}

function grade_form_html(array $a): string {
    $auto = array_key_exists('auto_score', $a) ? $a['auto_score'] : ($a['graded_by'] === null ? $a['score_pct'] : null);
    $open = $auto === null;
    $verified = !empty($a['verified_at']);
    $h = '<form method="post" class="grade-form">' . csrf_field() . '<input type="hidden" name="action" value="grade"><input type="hidden" name="attempt" value="' . (int)$a['id'] . '">';
    if (!$open) {
        $h .= '<div class="row" style="gap:6px;margin-bottom:6px"><span class="small">🤖 Rezultati automatik i librit: ' . score_html((int)$auto) . '</span>'
            . '<button class="btn btn-sm btn-primary" name="decision" value="confirm">✔ Konfirmo ' . (int)$auto . '%</button>'
            . '<span class="small muted">ose ndrysho:</span></div>';
    }
    $cur = $a['score_pct'] !== null ? (int)$a['score_pct'] : null;
    $h .= '<div class="grade-scores" role="radiogroup" aria-label="Vlerësimi i mësuesit">';
    foreach ([0 => '😟 0%', 25 => '🙁 25%', 50 => '😐 50%', 75 => '🙂 75%', 100 => '🌟 100%'] as $v => $l) {
        $chk = ($open || $a['graded_by'] !== null) && $cur === $v;
        $h .= '<label><input type="radio" name="score" value="' . $v . '"' . ($chk ? ' checked' : '') . '><span>' . $l . '</span></label>';
    }
    $h .= '</div>';
    $h .= '<div class="row"><input name="note" value="' . e($a['teacher_note'] ?? '') . '" placeholder="Koment për nxënësin (p.sh. Shumë mirë! Kujdes me renditjen.)" style="flex:1;min-width:200px">'
        . '<button class="btn btn-sm' . ($open ? ' btn-primary' : '') . '" name="decision" value="adjust">' . ($open ? '✍️ Ruaj vlerësimin' : '💾 Ruaj') . '</button></div>';
    if ($verified) $h .= '<p class="small muted" style="margin:4px 0 0">✔ Verifikuar ' . e(fmt_dt($a['verified_at'])) . ($a['graded_by'] ? ' · rezultati i ndryshuar nga mësuesi' : '') . '</p>';
    return $h . '</form>';
}

function answers_html(?string $json, ?string $type = null): string {
    $ans = json_decode((string)$json, true) ?: [];
    if (empty($ans['items'])) return '<p class="muted small">Pa përgjigje të ruajtura.</p>';
    $order = str_contains((string)$type, 'ordering');
    $items = $ans['items'];
    if ($order) usort($items, fn($x, $y) => (int)($x['value'] ?? 0) <=> (int)($y['value'] ?? 0));
    $h = '<table class="t"><tbody>';
    $choice = (bool)preg_match('/quiz|multiple_choice|multi_select|true_false/', (string)$type);
    foreach ($items as $it) {
        $v = $it['value'] ?? '';
        $vs = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        if ($choice && $vs === '' && !in_array($it['state'] ?? '', ['correct', 'wrong'], true)) continue;   // opsionet që nxënësi nuk i ka zgjedhur
        if ($vs === 'true') $vs = '● e zgjedhur nga nxënësi';
        $h .= '<tr><td style="width:30px">' . (['correct' => '✅', 'wrong' => '❌', 'empty' => '⬜'][$it['state'] ?? ''] ?? '✍️') . '</td>'
            . ($order ? '<td style="width:40px"><strong>' . e($vs) . '.</strong></td><td>' . e($it['label'] ?? $it['id'] ?? '') . '</td>'
                      : '<td class="small muted">' . e($it['label'] ?? $it['id'] ?? '') . '</td><td><strong>' . nl2br(e($vs)) . '</strong></td>') . '</tr>';
    }
    return $h . '</tbody></table>';
}

/** Historia e tentativave të një nxënësi për një faqe: 40% → 80% → 100% */
function attempt_history_html(int $studentId, int $bookId, string $section): string {
    $r = qa('SELECT score_pct, auto_score, created_at FROM quiz_attempts WHERE student_id=? AND book_id=? AND section_id=? ORDER BY id', [$studentId, $bookId, $section]);
    if (count($r) < 2) return '';
    $parts = array_map(fn($x) => $x['auto_score'] !== null ? (int)$x['auto_score'] . '%' : '✍️', $r);
    return '<div class="small muted">🔁 ' . count($r) . ' tentativa: ' . e(implode(' → ', array_slice($parts, -8))) . '</div>';
}

/** Emri i lexueshëm i një çelësi të punës së shkruar */
function work_key_label(string $k): string {
    if ($k === 'notepad-content') return '🗒️ Blloku i shënimeve';
    if (preg_match('/^txt:pg\d+_sec\d+:(.+)$/', $k, $m)) return '✍️ ' . $m[1];
    if (preg_match('/^pg\d+_sec\d+_(.+)$/', $k, $m)) return '✍️ Fusha ' . $m[1];
    return $k;
}

/** Pyetjet e një faqeje (id e fushës → teksti i pyetjes), nga tentativa e fundit e ruajtur për atë faqe */
function work_labels(int $bookId, string $section): array {
    static $cache = [];
    $k = $bookId . '|' . $section;
    if (isset($cache[$k])) return $cache[$k];
    $map = [];
    try {
        $j = qv('SELECT answers_json FROM quiz_attempts WHERE book_id=? AND section_id=? AND answers_json IS NOT NULL ORDER BY id DESC LIMIT 1', [$bookId, $section]);
        foreach ((json_decode((string)$j, true)['items'] ?? []) as $it) if (!empty($it['id']) && !empty($it['label'])) $map[(string)$it['id']] = (string)$it['label'];
    } catch (Throwable) {}
    return $cache[$k] = $map;
}
/** Emri i fushës së punës së shkruar: pyetja e ushtrimit kur dihet, përndryshe emri teknik */
function work_item_label(int $bookId, ?string $section, string $key): string {
    if ($section && preg_match('/^(?:txt:)?pg\d+_sec\d+[_:](.+)$/', $key, $m)) {
        $l = work_labels($bookId, $section)[$m[1]] ?? '';
        if ($l !== '') return '✍️ ' . mb_strimwidth($l, 0, 140, '…');
    }
    return work_key_label($key);
}

/** Tekstet e ruajtura automatikisht (edhe pa "Dërgo") për një faqe — shfaqen pranë tentativës kur nuk janë tashmë në të */
function work_for_section_html(int $studentId, int $bookId, string $section, ?string $answersJson = null): string {
    try { $w = qa("SELECT item_key, value, updated_at FROM student_work WHERE student_id=? AND book_id=? AND section_id=? AND TRIM(COALESCE(value,'')) <> '' ORDER BY item_key", [$studentId, $bookId, $section]); }
    catch (Throwable) { return ''; }
    $have = [];
    foreach ((json_decode((string)$answersJson, true)['items'] ?? []) as $it) if (is_scalar($it['value'] ?? null)) $have[trim((string)$it['value'])] = true;
    $w = array_values(array_filter($w, fn($r) => !isset($have[trim((string)$r['value'])])));
    if (!$w) return '';
    $h = '<div class="small" style="margin-top:6px;padding:6px 10px;background:#f6f8fb;border-radius:8px"><strong>📝 Tekst tjetër i shkruar në këtë faqe</strong> <span class="muted">(ruajtur automatikisht, pa «Dërgo»)</span>';
    foreach ($w as $r) $h .= '<div style="margin-top:4px"><span class="muted">' . e(work_item_label($bookId, $section, $r['item_key'])) . ':</span> ' . nl2br(e(mb_strimwidth((string)$r['value'], 0, 1500, '…'))) . '</div>';
    return $h . '</div>';
}

/* ---------------- Pamja e grupuar sipas nxënësve (mësuesi + administratori) ---------------- */
function grading_css(): string {
    return '<style>
.ev-student{margin:0 0 10px;padding:0;overflow:hidden}
.ev-student>summary{cursor:pointer;padding:12px 16px;display:flex;gap:12px;align-items:center;justify-content:space-between;list-style:none}
.ev-student>summary::-webkit-details-marker{display:none}
.ev-student>summary::before{content:"▸";color:#8a93a6;margin-right:2px}.ev-student[open]>summary::before{content:"▾"}
.ev-student[open]>summary{border-bottom:1px solid var(--line,#e2e7f0);background:#f8fafc}
.ev-row{border-bottom:1px solid var(--line,#e2e7f0)}.ev-row:last-child{border-bottom:0}
.ev-row>summary{cursor:pointer;list-style:none;display:grid;grid-template-columns:22px minmax(0,2.4fr) minmax(0,1.2fr) 110px 150px 96px;gap:10px;align-items:center;padding:8px 16px;font-size:.92rem}
.ev-row>summary::-webkit-details-marker{display:none}.ev-row>summary:hover{background:#f6f8fb}.ev-row[open]>summary{background:#eef5ff}
.ev-body{padding:10px 16px 14px 48px;background:#fbfcfe}
.ev-head{display:grid;grid-template-columns:22px minmax(0,2.4fr) minmax(0,1.2fr) 110px 150px 96px;gap:10px;padding:6px 16px;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:#8a93a6}
.ev-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap}
.ev-pend{background:#fff4e0;color:#a15c07}.ev-ok{background:#e3f6ec;color:#14804a}.ev-adj{background:#e6f4fc;color:#0a7cc1}
@media(max-width:800px){.ev-row>summary,.ev-head{grid-template-columns:22px 1fr 96px}.ev-row>summary>:nth-child(3),.ev-row>summary>:nth-child(4),.ev-row>summary>:nth-child(5),.ev-head>:nth-child(3),.ev-head>:nth-child(4),.ev-head>:nth-child(5){display:none}.ev-body{padding-left:16px}}
</style>';
}

function grading_status_pill(array $a): string {
    if (empty($a['verified_at'])) return '<span class="ev-pill ev-pend">⏳ Pret verifikim</span>';
    if (!empty($a['graded_by']) && $a['auto_score'] !== null && $a['score_pct'] !== null && (int)$a['auto_score'] !== (int)$a['score_pct']) return '<span class="ev-pill ev-adj" title="Rezultati u ndryshua nga mësuesi">✍️ Ndryshuar</span>';
    return '<span class="ev-pill ev-ok">✔ Verifikuar</span>';
}

/**
 * Vlerësimet e palosura në rreshta, të kategorizuara sipas nxënësve.
 * $mode: 'teacher' (me formular vlerësimi) | 'admin' (vetëm lexim, me emrin e mësuesit).
 */
function grading_grouped_html(array $rows, string $mode, bool $bulk = false): string {
    if (!$rows) return '';
    $by = [];
    foreach ($rows as $a) $by[(int)$a['student_id']][] = $a;
    $h = grading_css();
    foreach ($by as $sid => $list) {
        $pend = count(array_filter($list, fn($a) => empty($a['verified_at'])));
        $sc = array_filter(array_map(fn($a) => $a['score_pct'], $list), fn($v) => $v !== null);
        $avg = $sc ? (int)round(array_sum($sc) / count($sc)) : null;
        $first = $list[0];
        $h .= '<details class="card ev-student"' . (count($by) === 1 ? ' open' : '') . '><summary><span style="flex:1"><strong>' . e($first['avatar'] ?? '') . ' ' . e($first['full_name']) . '</strong>'
            . ($mode === 'admin' ? ' <span class="small muted">· ' . e(trim(($first['class_name'] ?? '') . ' · ' . ($first['teacher_name'] ?? '') . ' · ' . ($first['school_name'] ?? ''), ' ·')) . '</span>' : '') . '</span>'
            . '<span class="small muted">' . count($list) . ' vlerësime</span>'
            . ($pend ? '<span class="ev-pill ev-pend">⏳ ' . $pend . '</span>' : '<span class="ev-pill ev-ok">✔ të gjitha</span>')
            . '<span class="small">Mesatarja: ' . score_html($avg) . '</span></summary>';
        $h .= '<div class="ev-head"><span></span><span>Libri · faqja</span><span>Lloji</span><span>Dërguar</span><span>Statusi</span><span>Rezultati</span></div>';
        foreach ($list as $a) {
            $open = $a['score_pct'] === null;
            $h .= '<details class="ev-row"><summary><span>'
                . ($bulk && !$open && empty($a['verified_at']) ? '<input type="checkbox" class="bulk-id" form="bulkForm" name="ids[]" value="' . (int)$a['id'] . '" aria-label="Zgjidh" style="width:auto;margin:0" onclick="event.stopPropagation()">' : '▸')
                . '</span><span><strong>' . ($a['page_number'] ? 'f. ' . (int)$a['page_number'] . ' · ' : '') . e(mb_strimwidth((string)($a['ptitle'] ?: $a['section_id']), 0, 60, '…')) . '</strong><span class="small muted"> · ' . e(mb_strimwidth((string)$a['book'], 0, 40, '…')) . '</span></span>'
                . '<span class="small muted">' . e(section_type_label($a['activity_type'])) . '</span>'
                . '<span class="small muted">' . e(ago($a['created_at'])) . '</span>'
                . '<span>' . grading_status_pill($a) . '</span>'
                . '<span>' . ($open ? '<span class="small muted">✍️ e hapur</span>' : score_html((int)$a['score_pct'])) . '</span></summary><div class="ev-body">';
            $h .= attempt_history_html((int)$a['student_id'], (int)$a['book_id'], (string)$a['section_id']);
            $h .= '<div class="mt">' . answers_html($a['answers_json'], $a['activity_type']) . '</div>';
            $h .= work_for_section_html((int)$a['student_id'], (int)$a['book_id'], (string)$a['section_id'], $a['answers_json']);
            if (!empty($a['feedback'])) $h .= '<p class="small muted" style="margin:4px 0">💬 Libri i tha nxënësit: “' . e($a['feedback']) . '”</p>';
            if ($mode === 'teacher') {
                $h .= grade_form_html($a);
            } else {
                $h .= '<div class="small" style="margin-top:8px;padding:8px 10px;border-left:3px solid #0A7CC1;background:#f3f9fd">';
                $h .= '🤖 Rezultati automatik: ' . ($a['auto_score'] !== null ? score_html((int)$a['auto_score']) : '<span class="muted">përgjigje e hapur</span>');
                if (!empty($a['verified_at'])) {
                    $h .= ' · 👩‍🏫 Rezultati i verifikuar: ' . score_html($a['score_pct'] !== null ? (int)$a['score_pct'] : null)
                        . '<br>✔ Verifikuar nga <strong>' . e($a['verifier_name'] ?? 'mësuesi') . '</strong> më ' . e(fmt_dt($a['verified_at']))
                        . (!empty($a['teacher_note']) ? '<br>💬 Komenti për nxënësin: “' . e($a['teacher_note']) . '”' : '')
                        . '<br>' . (!empty($a['student_seen']) ? '👀 Nxënësi e ka parë vlerësimin.' : '<span class="muted">Nxënësi ende s\'e ka parë.</span>');
                } else {
                    $h .= '<br>⏳ Pret verifikimin e mësuesit' . (!empty($a['teacher_name']) ? ' (' . e($a['teacher_name']) . ')' : '') . '.';
                }
                $h .= '</div>';
            }
            $h .= '</div></details>';
        }
        $h .= '</details>';
    }
    return $h;
}

/** Vlerësimet e verifikuara që nxënësi ende s'i ka parë (për numrin në meny) */
function student_new_grades(int $studentId): int {
    $extra = 0;
    try { $extra = (int)qv('SELECT COUNT(*) FROM student_work WHERE student_id=? AND verified_at IS NOT NULL AND student_seen=0', [$studentId]); } catch (Throwable) {}
    try { return $extra + (int)qv('SELECT COUNT(*) FROM quiz_attempts q WHERE q.student_id=? AND q.verified_at IS NOT NULL AND q.student_seen=0
        AND q.id = (SELECT MAX(q2.id) FROM quiz_attempts q2 WHERE q2.student_id=q.student_id AND q2.book_id=q.book_id AND q2.section_id=q.section_id)', [$studentId]); }
    catch (Throwable) { return $extra; }
}

/* ---------------- Vlerësimi i teksteve të shkruara (📝 Tekstet & shënimet) ---------------- */
function handle_work_grade_post(array $u): void {
    $sid = posti('student'); $bid = posti('book');
    if ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], $sid)) { flash('err', 'Nxënësi nuk u gjet.'); return; }
    if (post('action') === 'work_approve_all') {
        $n = q('UPDATE student_work SET verified_by=?, verified_at=NOW(), student_seen=0 WHERE student_id=? AND verified_at IS NULL AND TRIM(COALESCE(value,\'\')) <> \'\'' . ($bid ? ' AND book_id=' . $bid : ''), [$u['id'], $sid])->rowCount();
        if ($n) audit('grade', 'Nxënësi #' . $sid, "$n tekste u aprovuan");
        flash('ok', $n ? "✔ U aprovuan $n tekste." : 'Nuk ka tekste në pritje.');
        return;
    }
    $key = (string)post('key');
    $w = q1('SELECT * FROM student_work WHERE student_id=? AND book_id=? AND item_key=?', [$sid, $bid, $key]);
    if (!$w) { flash('err', 'Teksti nuk u gjet.'); return; }
    $note = mb_substr(trim((string)post('note')), 0, 500) ?: null;
    $score = post('score');
    $sc = ($score !== '' && is_numeric($score)) ? max(0, min(100, (int)$score)) : ($w['score'] !== null && post('decision') !== 'approve' ? (int)$w['score'] : null);
    if (post('decision') === 'approve' && ($score === '' || !is_numeric($score))) $sc = $w['score'] !== null ? (int)$w['score'] : null;
    q('UPDATE student_work SET score=?, teacher_note=?, verified_by=?, verified_at=NOW(), student_seen=0 WHERE student_id=? AND book_id=? AND item_key=?', [$sc, $note, $u['id'], $sid, $bid, $key]);
    audit('grade', 'Nxënësi #' . $sid, 'Tekst i vlerësuar' . ($sc !== null ? " · $sc%" : ' · aprovuar') . ($note ? ' · ' . mb_substr($note, 0, 80) : ''));
    flash('ok', $sc !== null ? "✍️ Teksti u vlerësua ($sc%)." : '✔ Teksti u aprovua.' . ($note ? ' Nxënësi e sheh komentin.' : ''));
}

function work_status_pill(array $w): string {
    if (empty($w['verified_at'])) return '<span class="ev-pill ev-pend">⏳ Pret</span>';
    return '<span class="ev-pill ev-ok">✔ ' . ($w['score'] !== null ? (int)$w['score'] . '%' : 'Aprovuar') . '</span>';
}

/** Formulari i vogël: aprovo / vlerëso / komento një tekst */
function work_grade_form_html(array $w): string {
    $h = '<form method="post" class="grade-form" style="margin-top:6px">' . csrf_field() . '<input type="hidden" name="action" value="work_grade">'
        . '<input type="hidden" name="student" value="' . (int)$w['student_id'] . '"><input type="hidden" name="book" value="' . (int)$w['book_id'] . '"><input type="hidden" name="key" value="' . e($w['item_key']) . '">';
    $h .= '<div class="grade-scores" role="radiogroup" aria-label="Vlerësimi">';
    foreach ([0 => '😟 0%', 25 => '🙁 25%', 50 => '😐 50%', 75 => '🙂 75%', 100 => '🌟 100%'] as $v => $l) {
        $h .= '<label><input type="radio" name="score" value="' . $v . '"' . ($w['score'] !== null && (int)$w['score'] === $v ? ' checked' : '') . '><span>' . $l . '</span></label>';
    }
    $h .= '</div><div class="row"><input name="note" value="' . e($w['teacher_note'] ?? '') . '" placeholder="Koment për nxënësin (opsional)" style="flex:1;min-width:180px">'
        . '<button class="btn btn-sm btn-primary" name="decision" value="grade">✍️ Ruaj vlerësimin</button>'
        . '<button class="btn btn-sm" name="decision" value="approve" title="Aprovo pa notë">✔ Vetëm aprovo</button></div>';
    if (!empty($w['verified_at'])) $h .= '<p class="small muted" style="margin:4px 0 0">✔ Vlerësuar ' . e(fmt_dt($w['verified_at'])) . ' · ' . (!empty($w['student_seen']) ? '👀 nxënësi e ka parë' : 'nxënësi ende s\'e ka parë') . '</p>';
    return $h . '</form>';
}

/** Tekstet e nxënësve të grupuara sipas nxënësit, të palosura në rreshta. $mode: teacher | admin */
function work_grouped_html(array $work, string $mode): string {
    if (!$work) return '';
    $by = [];
    foreach ($work as $w) $by[(int)$w['student_id']][] = $w;
    $h = grading_css();
    foreach ($by as $sid => $ws) {
        $pend = count(array_filter($ws, fn($w) => empty($w['verified_at'])));
        $h .= '<details class="card ev-student"' . (count($by) === 1 ? ' open' : '') . '><summary><span style="flex:1"><strong>' . e($ws[0]['avatar'] ?? '') . ' ' . e($ws[0]['full_name']) . '</strong>'
            . ($mode === 'admin' ? ' <span class="small muted">· ' . e(trim(($ws[0]['class_name'] ?? '') . ' · ' . ($ws[0]['teacher_name'] ?? ''), ' ·')) . '</span>' : '') . '</span>'
            . '<span class="small muted">' . count($ws) . ' tekste</span>' . ($pend ? '<span class="ev-pill ev-pend">⏳ ' . $pend . '</span>' : '<span class="ev-pill ev-ok">✔ të gjitha</span>') . '</summary>';
        if ($mode === 'teacher' && $pend > 1) {
            $h .= '<form method="post" style="padding:8px 16px;border-bottom:1px solid var(--line,#e2e7f0)" data-confirm="T\'i aprovoj të gjitha tekstet në pritje të këtij nxënësi?">' . csrf_field()
                . '<input type="hidden" name="action" value="work_approve_all"><input type="hidden" name="student" value="' . $sid . '"><button class="btn btn-sm">✔ Aprovo të gjitha (' . $pend . ') pa notë</button></form>';
        }
        $h .= '<div class="ev-head"><span></span><span>Libri · faqja · pyetja</span><span>Çka ka shkruar</span><span>Ruajtur</span><span></span><span>Vlerësimi</span></div>';
        foreach ($ws as $w) {
            $h .= '<details class="ev-row"><summary><span>▸</span><span><strong>' . ($w['page_number'] ? 'f. ' . (int)$w['page_number'] . ' · ' : '') . e(mb_strimwidth((string)$w['book'], 0, 34, '…')) . '</strong>'
                . '<div class="small muted">' . e(mb_strimwidth(work_item_label((int)$w['book_id'], $w['section_id'], $w['item_key']), 0, 90, '…')) . '</div></span>'
                . '<span class="small">' . e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$w['value']), 0, 70, '…')) . '</span>'
                . '<span class="small muted">' . e(ago($w['updated_at'])) . '</span><span></span><span>' . work_status_pill($w) . '</span></summary><div class="ev-body">';
            $h .= '<div class="small muted">' . e(work_item_label((int)$w['book_id'], $w['section_id'], $w['item_key'])) . '</div>'
                . '<div style="margin:4px 0 8px;padding:8px 10px;background:#fff;border:1px solid var(--line,#e2e7f0);border-radius:8px">' . nl2br(e(mb_strimwidth((string)$w['value'], 0, 4000, '…'))) . '</div>';
            if ($mode === 'teacher') $h .= work_grade_form_html($w);
            else {
                $h .= '<div class="small" style="padding:8px 10px;border-left:3px solid #0A7CC1;background:#f3f9fd">' . (empty($w['verified_at']) ? '⏳ Pret vlerësimin e mësuesit.'
                    : '✔ Vlerësuar nga <strong>' . e($w['verifier_name'] ?? 'mësuesi') . '</strong> më ' . e(fmt_dt($w['verified_at'])) . ' · ' . ($w['score'] !== null ? 'rezultati ' . score_html((int)$w['score']) : 'aprovuar pa notë')
                      . (!empty($w['teacher_note']) ? '<br>💬 “' . e($w['teacher_note']) . '”' : '') . '<br>' . (!empty($w['student_seen']) ? '👀 Nxënësi e ka parë.' : '<span class="muted">Nxënësi ende s\'e ka parë.</span>')) . '</div>';
            }
            $h .= '</div></details>';
        }
        $h .= '</details>';
    }
    return $h;
}

function pending_work_count(int $teacherId): int {
    try { return (int)qv("SELECT COUNT(*) FROM student_work w WHERE w.verified_at IS NULL AND TRIM(COALESCE(w.value,'')) <> ''
        AND w.student_id IN (SELECT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=?)", [$teacherId]); }
    catch (Throwable) { return 0; }
}
