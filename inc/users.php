<?php
declare(strict_types=1);

/** Krijon nxënës; kthen [id, username, password, seq] */
function create_student(string $fullName, int $schoolId, ?int $grade, ?int $classId, int $createdBy, ?string $username = null, ?string $password = null, ?string $seq = null, array $demo = []): array {
    $fullName = trim(preg_replace('/\s+/u', ' ', $fullName));
    if ($fullName === '') throw new RuntimeException('Emri i nxënësit mungon.');
    // Anonimizimi: në sistem ruhet vetëm kodi; emri i vërtetë kthehet vetëm te thirrësi (lista-çelës)
    $realName = $fullName; $nameHash = null;
    if ($classId && anon_enabled() && !anon_is_code($fullName)) {
        $cls = q1('SELECT * FROM classes WHERE id=?', [$classId]);
        if ($cls) {
            $nameHash = anon_name_hash($realName, $classId);
            $fullName = anon_next_code($cls);
            $username = strtolower($fullName);
            if (qv('SELECT 1 FROM users WHERE username=?', [$username])) $username .= '-' . random_int(10, 99);
        }
    }
    $username = $username ? strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $username)) : make_username($fullName);
    if ($username === '' || qv('SELECT 1 FROM users WHERE username=?', [$username])) {
        throw new RuntimeException('Përdoruesi "' . $username . '" ekziston tashmë.');
    }
    $password = $password ?: make_kid_password();
    $seq = ($seq && picture_seq_valid($seq)) ? $seq : random_picture_seq();
    $avatars = avatar_set();
    q('INSERT INTO users (role, school_id, full_name, username, password_hash, picture_hash, picture_hint, plain_hint, avatar, grade, created_by)
       VALUES (\'student\',?,?,?,?,?,?,?,?,?,?)', [
        $schoolId, $fullName, $username, password_hash($password, PASSWORD_DEFAULT), password_hash($seq, PASSWORD_DEFAULT), enc_hint($seq), enc_hint($password),
        $avatars[random_int(0, count($avatars) - 1)], $grade, $createdBy,
    ]);
    $id = (int)db()->lastInsertId();
    if ($nameHash) q('UPDATE users SET name_hash=? WHERE id=?', [$nameHash, $id]);
    if ($demo) save_demographics($id, $demo + ['gender' => null, 'special_needs' => null, 'special_needs_type' => null, 'mother_tongue' => null]);
    if ($classId) q('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?,?)', [$classId, $id]);
    return ['id' => $id, 'username' => $username, 'password' => $password, 'seq' => $seq, 'code' => $fullName, 'real_name' => $realName];
}

function reset_student_credentials(int $studentId, ?string $password = null, ?string $seq = null): array {
    $password = $password ?: make_kid_password();
    $seq = ($seq && picture_seq_valid($seq)) ? $seq : random_picture_seq();
    q('UPDATE users SET password_hash=?, plain_hint=?, picture_hash=?, picture_hint=? WHERE id=? AND role=\'student\'', [
        password_hash($password, PASSWORD_DEFAULT), enc_hint($password), password_hash($seq, PASSWORD_DEFAULT), enc_hint($seq), $studentId,
    ]);
    return ['password' => $password, 'seq' => $seq];
}

/**
 * Kontrollon dhe pastron të dhënat e kontaktit të mësuesit (të detyrueshme).
 * @return array [email, phone, municipality]
 */
function validate_teacher_contact(string $email, string $phone, string $municipality, int $exceptId = 0): array {
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email-i i mësuesit mungon ose nuk është i vlefshëm.');
    if (qv("SELECT 1 FROM users WHERE email = ? AND role = 'teacher' AND id <> ?", [$email, $exceptId])) {
        throw new RuntimeException('Email-i "' . $email . '" përdoret tashmë nga një mësues tjetër.');
    }
    $p = normalize_phone($phone);
    if ($p === null) throw new RuntimeException('Telefoni mungon ose nuk është i vlefshëm (p.sh. +383 44 123 456).');
    if (!valid_municipality($municipality)) throw new RuntimeException('Zgjidhni komunën e mësuesit.');
    return [mb_strtolower($email), $p, $municipality];
}

function create_teacher(string $fullName, string $username, string $password, string $email, string $phone, string $municipality, int $schoolId, int $createdBy): int {
    $username = strtolower(preg_replace('/[^A-Za-z0-9._-]/', '', $username));
    if (trim($fullName) === '' || $username === '') throw new RuntimeException('Emri dhe përdoruesi janë të detyrueshëm.');
    if (strlen($password) < 8) throw new RuntimeException('Fjalëkalimi i mësuesit duhet të ketë së paku 8 karaktere.');
    if (qv('SELECT 1 FROM users WHERE username=?', [$username])) throw new RuntimeException('Përdoruesi "' . $username . '" ekziston tashmë.');
    [$email, $phone, $municipality] = validate_teacher_contact($email, $phone, $municipality);
    q('INSERT INTO users (role, school_id, full_name, username, email, phone, municipality, password_hash, created_by) VALUES (\'teacher\',?,?,?,?,?,?,?,?)',
        [$schoolId, trim($fullName), $username, $email, $phone, $municipality, password_hash($password, PASSWORD_DEFAULT), $createdBy]);
    return (int)db()->lastInsertId();
}

/**
 * Statistikat e progresit për një grup nxënësish në një libër.
 * Kthen [student_id => [...]]
 */
function progress_for(array $studentIds, int $bookId): array {
    if (!$studentIds) return [];
    $in = implode(',', array_map('intval', $studentIds));
    $book = q1('SELECT page_count, activity_count, package_type FROM books WHERE id=?', [$bookId]);
    $out = [];
    foreach ($studentIds as $sid) {
        $out[(int)$sid] = ['pages' => 0, 'seconds' => 0, 'last_seen' => null, 'acts_done' => 0, 'attempts' => 0, 'avg_score' => null,
            'sessions' => 0, 'days' => 0, 'page_total' => (int)$book['page_count'], 'act_total' => (int)$book['activity_count'], 'progress' => 0, 'status' => null];
    }
    foreach (qa("SELECT student_id, COUNT(*) pages, SUM(seconds) secs, MAX(last_seen) ls FROM page_views WHERE book_id=? AND student_id IN ($in) GROUP BY student_id", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['pages'] = (int)$r['pages'];
        $out[(int)$r['student_id']]['seconds'] = (int)$r['secs'];
        $out[(int)$r['student_id']]['last_seen'] = $r['ls'];
    }
    foreach (qa("SELECT student_id, COUNT(*) n FROM learning_sessions WHERE book_id=? AND student_id IN ($in) GROUP BY student_id", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['sessions'] = (int)$r['n'];
    }
    foreach (qa("SELECT student_id, COUNT(DISTINCT day) n FROM usage_daily WHERE book_id=? AND student_id IN ($in) GROUP BY student_id", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['days'] = (int)$r['n'];
    }
    foreach (qa("SELECT student_id, COUNT(*) att, COUNT(DISTINCT section_id) acts FROM quiz_attempts WHERE book_id=? AND student_id IN ($in) GROUP BY student_id", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['attempts'] = (int)$r['att'];
        $out[(int)$r['student_id']]['acts_done'] = (int)$r['acts'];
    }
    // nota mesatare = mesatarja e tentativës më të mirë për çdo ushtrim të vlerësuar automatikisht
    foreach (qa("SELECT student_id, AVG(best) avg_s FROM (SELECT student_id, section_id, MAX(score_pct) best FROM quiz_attempts
                 WHERE book_id=? AND student_id IN ($in) AND score_pct IS NOT NULL GROUP BY student_id, section_id) x GROUP BY student_id", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['avg_score'] = $r['avg_s'] !== null ? (int)round((float)$r['avg_s']) : null;
    }
    foreach (qa("SELECT student_id, lesson_status, score_raw FROM scorm_data WHERE book_id=? AND student_id IN ($in)", [$bookId]) as $r) {
        $out[(int)$r['student_id']]['status'] = $r['lesson_status'];
        if ($book['package_type'] !== 'adt' && $out[(int)$r['student_id']]['avg_score'] === null && is_numeric($r['score_raw'])) {
            $out[(int)$r['student_id']]['avg_score'] = (int)$r['score_raw'];
        }
    }
    foreach ($out as $sid => &$o) {
        if ($book['package_type'] === 'adt') {
            // 60% faqet e lexuara + 40% ushtrimet e kryera
            $o['progress'] = $o['act_total'] > 0
                ? (int)round(0.6 * pct($o['pages'], $o['page_total']) + 0.4 * pct($o['acts_done'], $o['act_total']))
                : pct($o['pages'], $o['page_total']);
        } else {
            $o['progress'] = in_array($o['status'], ['completed', 'passed'], true) ? 100 : ($o['status'] ? 50 : ($o['pages'] ? 10 : 0));
        }
    }
    return $out;
}

function score_html(?int $s): string {
    if ($s === null) return '<span class="muted">—</span>';
    $c = $s >= 80 ? 'good' : ($s >= 50 ? 'mid' : 'low');
    return '<span class="score ' . $c . '">' . $s . '%</span>';
}
