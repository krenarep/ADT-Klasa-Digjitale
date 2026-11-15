<?php
// API e ndjekjes: shikimet e faqeve, koha, tentativat e ushtrimeve, të dhënat SCORM, tiketat nga lexuesi
require __DIR__ . '/../inc/bootstrap.php';

$u = current_user();
if (!$u || $u['role'] !== 'student') json_out(['ok' => false, 'error' => 'auth'], 401);
if (!is_post()) json_out(['ok' => false, 'error' => 'method'], 405);

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_GET['csrf'] ?? '');
if (!is_string($token) || !hash_equals(csrf_token(), $token)) json_out(['ok' => false, 'error' => 'csrf'], 419);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'json'], 400);

$sid = (int)$u['id'];
$bookId = (int)($in['book'] ?? 0);
$type = (string)($in['type'] ?? '');
// kërkesat e ruajtura pa internet vijnë me orën e tyre (lejohen deri 7 ditë prapa)
$queued = !empty($in['q']);
$ts = (int)($in['ts'] ?? 0);
$when = ($queued && $ts > time() - 7 * 86400 && $ts <= time() + 60) ? $ts : time();
$whenDt = date('Y-m-d H:i:s', $when);
$whenDay = date('Y-m-d', $when);

// Ngjarjet e portalit (pa libër): A+, kontrast, lexim me zë në faqet e nxënësit
if ($type === 'events' && !$bookId) {
    record_events($sid, null, null, (array)($in['events'] ?? []));
    json_out(['ok' => true]);
}
if (!$bookId || !student_has_book($sid, $bookId)) json_out(['ok' => false, 'error' => 'book'], 403);

// seanca aktuale e leximit
$sessionId = (int)($in['session'] ?? 0);
if ($sessionId && !qv('SELECT 1 FROM learning_sessions WHERE id=? AND student_id=? AND book_id=?', [$sessionId, $sid, $bookId])) $sessionId = 0;
if (!$sessionId && $type !== 'start') {
    $sessionId = (int)qv('SELECT id FROM learning_sessions WHERE student_id=? AND book_id=? AND last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1', [$sid, $bookId]);
}

function record_events(int $sid, ?int $bookId, ?int $sessionId, array $events): void {
    $cat = feature_catalog();
    $st = db()->prepare('INSERT INTO interaction_events (student_id, book_id, session_id, section_id, feature, action, label, value, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $now = time();
    foreach (array_slice($events, 0, 100) as $ev) {
        if (!is_array($ev)) continue;
        $f = (string)($ev['f'] ?? '');
        if (!isset($cat[$f])) $f = 'content';
        $t = (int)($ev['t'] ?? $now);
        if ($t > $now + 60 || $t < $now - 3600) $t = $now;   // ora e klientit e pavlefshme
        $st->execute([$sid, $bookId, $sessionId ?: null, substr((string)($ev['s'] ?? ''), 0, 80) ?: null, $f,
            substr(preg_replace('/[^a-z_]/', '', (string)($ev['a'] ?? 'click')), 0, 20) ?: 'click',
            mb_substr((string)($ev['l'] ?? ''), 0, 120) ?: null, mb_substr((string)($ev['v'] ?? ''), 0, 120) ?: null, date('Y-m-d H:i:s', $t)]);
    }
}
$section = substr((string)($in['section'] ?? ''), 0, 80);
$validSection = $section !== '' && qv('SELECT 1 FROM book_pages WHERE book_id=? AND section_id=?', [$bookId, $section]);

switch ($type) {
    case 'start':
        $existing = (int)qv('SELECT id FROM learning_sessions WHERE student_id=? AND book_id=? AND last_seen > DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1', [$sid, $bookId]);
        if ($existing) json_out(['ok' => true, 'session' => $existing]);
        q('INSERT INTO learning_sessions (student_id, book_id, device) VALUES (?,?,?)', [$sid, $bookId, mb_substr((string)($in['device'] ?? ''), 0, 20) ?: null]);
        json_out(['ok' => true, 'session' => (int)db()->lastInsertId()]);

    case 'events':
        record_events($sid, $bookId, $sessionId ?: null, (array)($in['events'] ?? []));
        json_out(['ok' => true]);

    case 'view':
        if (!$validSection) json_out(['ok' => false, 'error' => 'section'], 400);
        q('INSERT INTO page_views (student_id, book_id, section_id, visits) VALUES (?,?,?,1)
           ON DUPLICATE KEY UPDATE visits = visits + 1, last_seen = NOW()', [$sid, $bookId, $section]);
        q('INSERT INTO scorm_data (student_id, book_id, lesson_location, lesson_status) VALUES (?,?,?,\'incomplete\')
           ON DUPLICATE KEY UPDATE lesson_location = VALUES(lesson_location), updated_at = NOW()', [$sid, $bookId, $section]);
        if ($sessionId) q('UPDATE learning_sessions SET pages = pages + 1, last_seen = NOW() WHERE id=?', [$sessionId]);
        $n = (int)qv('SELECT COUNT(*) FROM page_views WHERE student_id=? AND book_id=?', [$sid, $bookId]);
        json_out(['ok' => true, 'pages_visited' => $n]);

    case 'beat':
        if (!$validSection) json_out(['ok' => false, 'error' => 'section'], 400);
        // mbrojtje nga fryrja e kohës: s'mund të kreditohet më shumë se koha reale që nga rrahja e fundit
        $now = time();
        if ($queued) {
            // koha e regjistruar pa internet: maksimumi 60 sek për kërkesë, 4 orë në ditë
            $secs = max(0, min((int)($in['seconds'] ?? 0), 60));
            $already = (int)qv('SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=? AND day=?', [$sid, $whenDay]);
            if ($already + $secs > 14400) $secs = max(0, 14400 - $already);
        } else {
            $last = $_SESSION['beat'][$bookId] ?? ($now - 30);
            $secs = max(0, min((int)($in['seconds'] ?? 0), 60, $now - $last + 3));
            $_SESSION['beat'][$bookId] = $now;
        }
        if ($secs > 0) {
            q('INSERT INTO page_views (student_id, book_id, section_id, seconds, visits) VALUES (?,?,?,?,1)
               ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds), last_seen = NOW()', [$sid, $bookId, $section, $secs]);
            q('INSERT INTO usage_daily (student_id, book_id, day, seconds) VALUES (?,?,?,?)
               ON DUPLICATE KEY UPDATE seconds = seconds + VALUES(seconds)', [$sid, $bookId, $whenDay, $secs]);
            if ($sessionId) q('UPDATE learning_sessions SET seconds = seconds + ?, last_seen = NOW() WHERE id=?', [$secs, $sessionId]);
            q('INSERT INTO scorm_data (student_id, book_id, total_time_sec) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE total_time_sec = total_time_sec + VALUES(total_time_sec)', [$sid, $bookId, $secs]);
        }
        json_out(['ok' => true, 'credited' => $secs]);

    case 'attempt':
        if (!$validSection) json_out(['ok' => false, 'error' => 'section'], 400);
        $answers = json_encode($in['answers'] ?? null, JSON_UNESCAPED_UNICODE);
        if (strlen((string)$answers) > 200000) $answers = null;
        // mos regjistro dyfish të njëjtën dërgesë (të njëjtat përgjigje) brenda 5 sekondave
        $last = q1('SELECT answers_json FROM quiz_attempts WHERE student_id=? AND book_id=? AND section_id=? AND created_at BETWEEN DATE_SUB(?, INTERVAL 5 SECOND) AND DATE_ADD(?, INTERVAL 5 SECOND) ORDER BY id DESC LIMIT 1', [$sid, $bookId, $section, $whenDt, $whenDt]);
        if ($last && (string)$last['answers_json'] === (string)$answers) json_out(['ok' => true, 'dup' => true]);
        $score = $in['score'] ?? null;
        $score = is_numeric($score) ? max(0, min(100, (int)$score)) : null;
        q('INSERT INTO quiz_attempts (student_id, book_id, section_id, activity_type, total_items, correct_items, wrong_items, empty_items, score_pct, answers_json, feedback, created_at, auto_score)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $sid, $bookId, $section, substr((string)($in['activity_type'] ?? ''), 0, 60),
            max(0, (int)($in['total'] ?? 0)), max(0, (int)($in['correct'] ?? 0)), max(0, (int)($in['wrong'] ?? 0)), max(0, (int)($in['empty'] ?? 0)),
            $score, $answers, mb_substr((string)($in['feedback'] ?? ''), 0, 250), $whenDt, $score,
        ]);
        $stars = (int)qv('SELECT COUNT(DISTINCT book_id, section_id) FROM quiz_attempts WHERE student_id=?', [$sid]);
        json_out(['ok' => true, 'stars' => $stars]);

    case 'work':
        // Tekstet që nxënësi shkruan në libër (edhe pa "Dërgo") dhe shënimet (blloku i shënimeve)
        $n = 0;
        foreach (array_slice((array)($in['items'] ?? []), 0, 200) as $it) {
            if (!is_array($it)) continue;
            $k = (string)($it['k'] ?? '');
            if ($k === '' || strlen($k) > 190 || !preg_match('/^(pg\d+_sec\d+_|txt:|notepad-content$)/', $k)) continue;
            $sec = null;
            if (preg_match('/^(?:txt:)?(pg\d+_sec\d+)/', $k, $m)) $sec = $m[1];
            $v = $it['v'] ?? null;
            if ($v === null) continue;
            $v = mb_substr((string)$v, 0, 20000);
            q('INSERT INTO student_work (student_id, book_id, item_key, section_id, value, updated_at) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE updated_at=IF(value <=> VALUES(value), updated_at, VALUES(updated_at)), section_id=VALUES(section_id), value=VALUES(value)', [$sid, $bookId, $k, $sec, $v, $whenDt]);
            // nëse nxënësi e ndryshon tekstin pasi mësuesja e ka vlerësuar, teksti pret vlerësim të ri
            try { q('UPDATE student_work SET verified_at=NULL, verified_by=NULL, score=NULL, student_seen=0 WHERE student_id=? AND book_id=? AND item_key=? AND verified_at IS NOT NULL AND verified_at < updated_at', [$sid, $bookId, $k]); } catch (Throwable) {}
            $n++;
        }
        json_out(['ok' => true, 'saved' => $n]);

    case 'scorm':
        $d = is_array($in['data'] ?? null) ? $in['data'] : [];
        $status = in_array($d['lesson_status'] ?? '', ['passed', 'completed', 'failed', 'incomplete', 'browsed', 'not attempted'], true) ? $d['lesson_status'] : null;
        q('INSERT INTO scorm_data (student_id, book_id, lesson_location, lesson_status, score_raw, suspend_data) VALUES (?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE lesson_location = COALESCE(NULLIF(VALUES(lesson_location), \'\'), lesson_location),
             lesson_status = COALESCE(VALUES(lesson_status), lesson_status), score_raw = COALESCE(NULLIF(VALUES(score_raw), \'\'), score_raw),
             suspend_data = VALUES(suspend_data), updated_at = NOW()', [
            $sid, $bookId, substr((string)($d['lesson_location'] ?? ''), 0, 255), $status,
            substr((string)($d['score_raw'] ?? ''), 0, 10), substr((string)($d['suspend_data'] ?? ''), 0, 64000),
        ]);
        json_out(['ok' => true]);

    case 'ticket':
        $teacher = q1('SELECT c.teacher_id FROM classes c JOIN class_students cs ON cs.class_id=c.id JOIN class_books cb ON cb.class_id=c.id
                       WHERE cs.student_id=? AND cb.book_id=? LIMIT 1', [$sid, $bookId]);
        $msg = trim((string)($in['message'] ?? ''));
        if (!$teacher || $msg === '') json_out(['ok' => false, 'error' => 'invalid'], 400);
        $cat = (string)($in['category'] ?? 'other');
        $label = ticket_categories()[$cat][1] ?? 'Problem';
        $pg = $validSection ? q1('SELECT page_number FROM book_pages WHERE book_id=? AND section_id=?', [$bookId, $section]) : null;
        $subject = $label . ($pg && $pg['page_number'] ? ' (faqja ' . $pg['page_number'] . ')' : '');
        $id = ticket_create($u, 'teacher', (int)$teacher['teacher_id'], $cat === 'dont_understand' ? 'question' : 'problem', $cat, $subject, mb_substr($msg, 0, 4000), $bookId, $validSection ? $section : null);
        json_out(['ok' => true, 'id' => $id]);
}
json_out(['ok' => false, 'error' => 'type'], 400);
