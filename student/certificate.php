<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student', 'teacher', 'admin');
$bookId = geti('book');
$sid = $u['role'] === 'student' ? (int)$u['id'] : geti('student');
if ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], $sid)) { http_response_code(404); exit('Nuk u gjet.'); }
$s = q1("SELECT * FROM users WHERE id=? AND role='student'", [$sid]);
$b = q1('SELECT * FROM books WHERE id=?', [$bookId]);
if (!$s || !$b || !certificate_eligible($sid, $bookId)) {
    layout_start('Certifikata'); echo '<div class="kid-card">🔒 Certifikata nuk është ende e disponueshme për këtë libër. Vazhdo të lexosh dhe të plotësosh ushtrimet!</div>'; layout_end(); exit;
}
$t = q1('SELECT t.full_name, sc.name school FROM classes c JOIN class_students cs ON cs.class_id=c.id JOIN users t ON t.id=c.teacher_id JOIN schools sc ON sc.id=c.school_id WHERE cs.student_id=? LIMIT 1', [$sid]);
?><!DOCTYPE html><html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certifikata · <?= e($s['full_name']) ?></title><link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4">
<style>body{background:#eee;padding:16px}@media print{body{background:#fff;padding:0}.no-print{display:none}@page{size:A4 landscape;margin:8mm}}</style></head>
<body><p class="no-print" style="text-align:center"><button class="btn btn-primary btn-lg" onclick="window.print()">🖨️ Printo / Ruaj si PDF</button></p>
<?= certificate_html($s, $b, $t['full_name'] ?? '', $t['school'] ?? '') ?>
</body></html>
