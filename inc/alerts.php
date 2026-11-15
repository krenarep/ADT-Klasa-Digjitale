<?php
declare(strict_types=1);

function alert_kinds(): array {
    return [
        'inactive' => ['💤', 'Joaktiv'],
        'low_score' => ['🎯', 'Saktësi e ulët'],
        'overdue' => ['⏰', 'Detyrë e vonuar'],
        'grading' => ['✍️', 'Për t\'u vlerësuar'],
        'badge' => ['🏅', 'Arritje'],
        'journey' => ['🧭', 'Rrugëtimi'],
    ];
}

function add_alert(int $teacherId, ?int $studentId, string $kind, string $message, ?string $link, string $dedupe): void {
    q('INSERT IGNORE INTO alerts (teacher_id, student_id, kind, message, link, dedupe_key) VALUES (?,?,?,?,?,?)',
        [$teacherId, $studentId, $kind, mb_substr($message, 0, 255), $link, mb_substr($dedupe, 0, 120)]);
}

/** Gjeneron paralajmërimet për një mësues (idempotente — çelësi dedupe parandalon dyfishimet) */
function generate_alerts(int $tid): int {
    $before = (int)qv('SELECT COUNT(*) FROM alerts WHERE teacher_id=?', [$tid]);
    $week = date('o-W');
    $days = max(2, setting_int('alert_inactive_days'));
    $low = setting_int('alert_low_score');
    $minN = max(1, setting_int('alert_min_graded'));
    $students = qa("SELECT DISTINCT s.id, s.full_name, s.created_at, c.name class_name,
        (SELECT MAX(day) FROM usage_daily u WHERE u.student_id=s.id) last_day
        FROM users s JOIN class_students cs ON cs.student_id=s.id JOIN classes c ON c.id=cs.class_id
        WHERE c.teacher_id=? AND s.active=1 AND s.role='student'", [$tid]);

    foreach ($students as $s) {
        $sid = (int)$s['id'];
        $ref = $s['last_day'] ?: substr((string)$s['created_at'], 0, 10);
        $idle = (int)floor((strtotime(date('Y-m-d')) - strtotime($ref)) / 86400);
        if ($idle >= $days) {
            $msg = $s['last_day']
                ? "{$s['full_name']} ({$s['class_name']}) nuk ka lexuar asnjë libër prej $idle ditësh."
                : "{$s['full_name']} ({$s['class_name']}) nuk e ka hapur ende asnjë libër ($idle ditë nga regjistrimi).";
            add_alert($tid, $sid, 'inactive', $msg, 'teacher/student.php?id=' . $sid, "inactive:$sid:$week");
        }
    }
    // saktësia e ulët (14 ditët e fundit)
    foreach (qa("SELECT x.student_id, s.full_name, AVG(best) avg_s, COUNT(*) n FROM (
            SELECT q.student_id, q.book_id, q.section_id, MAX(q.score_pct) best FROM quiz_attempts q
            WHERE q.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND q.score_pct IS NOT NULL
              AND q.student_id IN (SELECT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=?)
            GROUP BY q.student_id, q.book_id, q.section_id) x JOIN users s ON s.id=x.student_id
        GROUP BY x.student_id HAVING n >= ? AND avg_s < ?", [$tid, $minN, $low]) as $r) {
        add_alert($tid, (int)$r['student_id'], 'low_score',
            "{$r['full_name']} ka saktësi mesatare " . round((float)$r['avg_s']) . "% në {$r['n']} ushtrimet e fundit — mund të ketë nevojë për ndihmë.",
            'teacher/student.php?id=' . $r['student_id'] . '#attempts', "low:{$r['student_id']}:$week");
    }
    // detyrat e vonuara (afati ka kaluar në 14 ditët e fundit)
    foreach (qa('SELECT a.* FROM assignments a JOIN classes c ON c.id=a.class_id WHERE c.teacher_id=? AND a.due_date < CURDATE() AND a.due_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)', [$tid]) as $a) {
        $ids = array_map('intval', array_column(qa('SELECT student_id FROM class_students WHERE class_id=?', [$a['class_id']]), 'student_id'));
        $prog = assignment_progress($a, $ids);
        $late = [];
        foreach ($prog as $sid => $p) if ($p['pct'] < 100) $late[] = $sid;
        if ($late) {
            $names = array_column(qa('SELECT full_name FROM users WHERE id IN (' . implode(',', $late) . ') ORDER BY full_name'), 'full_name');
            add_alert($tid, null, 'overdue', count($late) . ' nxënës nuk e kanë përfunduar detyrën "' . $a['title'] . '" (afati ' . date('d.m', strtotime($a['due_date'])) . '): '
                . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : ''), 'teacher/assignments.php?id=' . $a['id'], 'overdue:' . $a['id']);
        }
    }
    // përgjigje të hapura që presin vlerësim më shumë se 3 ditë
    $pending = (int)qv('SELECT COUNT(*) FROM quiz_attempts q WHERE q.score_pct IS NULL AND q.graded_by IS NULL AND q.created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
        AND q.student_id IN (SELECT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=?)', [$tid]);
    if ($pending) add_alert($tid, null, 'grading', "$pending përgjigje të hapura presin vlerësimin tuaj prej më shumë se 3 ditësh.", 'teacher/grading.php', "grading:$week");

    return (int)qv('SELECT COUNT(*) FROM alerts WHERE teacher_id=?', [$tid]) - $before;
}

function alerts_unread(int $tid): int {
    try { return (int)qv('SELECT COUNT(*) FROM alerts WHERE teacher_id=? AND read_at IS NULL', [$tid]); } catch (Throwable) { return 0; }
}
