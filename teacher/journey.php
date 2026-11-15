<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
if (is_post()) {
    csrf_check();
    try { journey_handle_post($u, (int)$u['id']); } catch (Throwable $ex) { flash('err', $ex->getMessage()); }
    redirect('teacher/journey.php' . (in_array(post('action'), ['plan', 'cycles'], true) || preg_match('/^c\d+_/', (string)post('step')) ? '#p5' : ''));
}
$J = journey_state((int)$u['id']);
layout_start('Rrugëtimi im në pilotim');
echo journey_css();
?>
<p class="muted">Këtu ndiqni hapat e metodologjisë së pilotimit. Dokumentet (doc, docx, xls, xlsx, pdf, ppt, pptx) ngarkohen te <strong>Projekti</strong> dhe te <strong>Intervenimi</strong>; administratori i shqyrton dhe ju lë koment.</p>
<?= journey_bar_html($J) ?>
<?php if ($J['needs_work']): ?><div class="flash flash-err">⚠️ <?= (int)$J['needs_work'] ?> hap(a) kanë nevojë për punë — shihni komentet më poshtë.</div><?php endif; ?>
<?= journey_detail_html($J, (int)$u['id'], 'teacher') ?>
<?php layout_end();
