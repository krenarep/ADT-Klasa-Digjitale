<?php
// Raporti mujor për prindërit — një nxënës ose e gjithë klasa (për printim)
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$ym = valid_month(get('month'));
$ids = [];
if ($sid = geti('student')) {
    if ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], $sid)) { http_response_code(404); exit('Nuk u gjet.'); }
    $ids = [$sid];
} elseif ($cid = geti('class')) {
    if ($u['role'] === 'teacher' && !teacher_owns_class((int)$u['id'], $cid)) { http_response_code(404); exit('Nuk u gjet.'); }
    $ids = array_map('intval', array_column(qa('SELECT s.id FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? AND s.active=1 ORDER BY s.full_name', [$cid]), 'id'));
}
audit('report', 'Raporti për prindërit · ' . month_label($ym), count($ids) . ' nxënës');
?><!DOCTYPE html><html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Raporti për prindërit · <?= e(month_label($ym)) ?></title><link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4">
<style>@media print{.no-print{display:none}@page{size:A4 portrait;margin:10mm}.preport{border:0;padding:0}}</style></head>
<body class="parent-page"><div class="wrap">
  <form class="no-print row mb" method="get">
    <?php if (!empty($sid)): ?><input type="hidden" name="student" value="<?= $sid ?>"><?php else: ?><input type="hidden" name="class" value="<?= (int)($cid ?? 0) ?>"><?php endif; ?>
    <select name="month" onchange="this.form.submit()" style="width:auto"><?= month_options($ym) ?></select>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨️ Printo / PDF</button>
    <span class="muted small"><?= count($ids) ?> raport(e)</span>
  </form>
  <?php foreach ($ids as $id) echo parent_report_html(parent_report_data($id, $ym)); ?>
  <?php if (!$ids): ?><p>Nuk ka nxënës.</p><?php endif; ?>
</div></body></html>
