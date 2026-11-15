<?php
declare(strict_types=1);

/** Faqet që përfshin detyra */
function assignment_sections(array $a): array {
    return qa('SELECT section_id, page_number, title, is_activity, href, position FROM book_pages WHERE book_id=? AND position BETWEEN ? AND ? ORDER BY position',
        [$a['book_id'], $a['pos_from'], $a['pos_to']]);
}

/** Progresi i detyrës për nxënësit: [sid => [pages, page_total, acts, act_total, pct, status]] */
function assignment_progress(array $a, array $studentIds): array {
    $secs = assignment_sections($a);
    $pageTotal = count($secs);
    $actIds = array_column(array_filter($secs, fn($s) => (int)$s['is_activity'] === 1), 'section_id');
    $actTotal = count($actIds);
    $out = [];
    foreach ($studentIds as $sid) $out[(int)$sid] = ['pages' => 0, 'page_total' => $pageTotal, 'acts' => 0, 'act_total' => $actTotal, 'pct' => 0, 'status' => 'new', 'last' => null];
    if (!$studentIds || !$pageTotal) return $out;
    $in = implode(',', array_map('intval', $studentIds));
    $secIn = implode(',', array_map(fn($s) => db()->quote($s['section_id']), $secs));
    foreach (qa("SELECT student_id, COUNT(*) n, MAX(last_seen) ls FROM page_views WHERE book_id=? AND section_id IN ($secIn) AND student_id IN ($in) GROUP BY student_id", [$a['book_id']]) as $r) {
        $out[(int)$r['student_id']]['pages'] = (int)$r['n'];
        $out[(int)$r['student_id']]['last'] = $r['ls'];
    }
    if ($actTotal) {
        $actIn = implode(',', array_map(fn($s) => db()->quote($s), $actIds));
        foreach (qa("SELECT student_id, COUNT(DISTINCT section_id) n FROM quiz_attempts WHERE book_id=? AND section_id IN ($actIn) AND student_id IN ($in) GROUP BY student_id", [$a['book_id']]) as $r) {
            $out[(int)$r['student_id']]['acts'] = (int)$r['n'];
        }
    }
    $overdue = $a['due_date'] < date('Y-m-d');
    foreach ($out as &$o) {
        $o['pct'] = $actTotal ? (int)round(50 * $o['pages'] / $pageTotal + 50 * $o['acts'] / $actTotal) : (int)round(100 * $o['pages'] / $pageTotal);
        $o['status'] = $o['pct'] >= 100 ? 'done' : ($overdue ? 'late' : ($o['pct'] > 0 ? 'progress' : 'new'));
    }
    return $out;
}

function assignment_status_badge(string $s): string {
    return match ($s) {
        'done' => '<span class="badge badge-closed">✅ Kryer</span>',
        'late' => '<span class="badge" style="background:#fdecea;color:#8c201b;border-color:#f4c0bc">⏰ Vonuar</span>',
        'progress' => '<span class="badge badge-in_progress">▶️ Në progres</span>',
        default => '<span class="badge badge-open">⬜ Pa filluar</span>',
    };
}

function due_label(string $due): string {
    $d = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
    if ($d < 0) return 'afati kaloi para ' . abs($d) . ' ditësh';
    if ($d === 0) return 'afati: SOT';
    if ($d === 1) return 'afati: nesër';
    return 'edhe ' . $d . ' ditë (' . date('d.m', strtotime($due)) . ')';
}

/** Detyrat e një nxënësi (nga klasat e tij) */
function student_assignments(int $sid, bool $onlyOpen = false): array {
    $rows = qa('SELECT a.*, b.title book_title, b.folder, c.name class_name, t.full_name teacher FROM assignments a JOIN class_students cs ON cs.class_id=a.class_id
        JOIN books b ON b.id=a.book_id JOIN classes c ON c.id=a.class_id JOIN users t ON t.id=c.teacher_id
        WHERE cs.student_id=? AND a.start_date <= CURDATE()' . ($onlyOpen ? ' AND a.due_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)' : '') . ' ORDER BY a.due_date', [$sid]);
    foreach ($rows as &$a) {
        $a['progress'] = assignment_progress($a, [$sid])[$sid];
        $a['first_section'] = (string)qv('SELECT section_id FROM book_pages WHERE book_id=? AND position=?', [$a['book_id'], $a['pos_from']]);
    }
    return $rows;
}
