<?php
// Verifikimi publik i certifikatave
require __DIR__ . '/inc/bootstrap.php';
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', get('c')));
$found = null;
if (strlen($code) === 10) {
    foreach (qa("SELECT DISTINCT pv.student_id, pv.book_id FROM page_views pv JOIN users u ON u.id=pv.student_id WHERE u.role='student'") as $r) {
        if (hash_equals(certificate_code((int)$r['student_id'], (int)$r['book_id']), $code)) { $found = $r; break; }
    }
}
$ok = $found && certificate_eligible((int)$found['student_id'], (int)$found['book_id']);
if ($ok) { $s = q1('SELECT full_name FROM users WHERE id=?', [$found['student_id']]); $b = q1('SELECT title FROM books WHERE id=?', [$found['book_id']]); }
layout_start('Verifikimi i certifikatës', ['body_class' => 'login-page']);
?>
<div class="card" style="max-width:560px;margin:8vh auto;text-align:center">
  <div style="font-size:3rem"><?= $ok ? '✅' : '❔' ?></div>
  <?php if ($ok): ?>
    <h2>Certifikata është e vlefshme</h2>
    <p><strong><?= e($s['full_name']) ?></strong> ka përfunduar librin <strong>“<?= e($b['title']) ?>”</strong>.</p>
    <p class="small muted">Kodi: <?= e($code) ?> · <?= e(cfg('app_name')) ?></p>
  <?php else: ?>
    <h2>Certifikata nuk u gjet</h2>
    <form method="get" class="row" style="justify-content:center"><input name="c" value="<?= e($code) ?>" placeholder="Kodi i certifikatës" style="max-width:220px;text-transform:uppercase"><button class="btn btn-primary">Verifiko</button></form>
  <?php endif; ?>
</div>
<?php layout_end();
