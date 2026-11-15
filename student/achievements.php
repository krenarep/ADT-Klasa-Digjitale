<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];
$res = compute_badges($sid);
$earned = $res['earned'];
$cat = badge_catalog();
$newKeys = array_keys(array_filter($earned, fn($e) => !(int)($e['seen'] ?? 0)));
q('UPDATE badges_earned SET seen=1 WHERE student_id=?', [$sid]);
$th = setting_int('cert_threshold') ?: 90;
$books = qa('SELECT DISTINCT b.* FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN class_students cs ON cs.class_id=cb.class_id WHERE cs.student_id=? ORDER BY b.title', [$sid]);
$st = $res['stats'];

layout_start('Arritjet e mia');
?>
<section class="kid-hello" style="background:linear-gradient(120deg,#fff1c9,#ffd9e6)">
  <span class="big" aria-hidden="true">🏅</span>
  <div><h1>Arritjet e mia</h1><p>Ke fituar <strong><?= count($earned) ?></strong> nga <?= count($cat) ?> distinktivë. Vazhdo kështu!</p></div>
  <span class="stars">🔥 <?= $st['streak'] ?> ditë rresht · ⏱️ <?= e(fmt_duration($st['secs'])) ?></span>
</section>

<div class="badges-grid">
<?php foreach ($cat as $k => [$ico, $name, $how]): $has = isset($earned[$k]); ?>
  <div class="badge-tile <?= $has ? (in_array($k, $newKeys, true) ? 'new' : '') : 'locked' ?>">
    <div class="bi" aria-hidden="true"><?= $ico ?></div>
    <strong><?= e($name) ?></strong>
    <div class="small muted"><?= $has ? '✅ ' . e(fmt_dt($earned[$k]['earned_at'], false)) : '🔒 ' . e($how) ?></div>
  </div>
<?php endforeach; ?>
</div>

<h2 style="font-size:1.4rem;margin-top:30px">🏆 Certifikatat</h2>
<div class="books-grid">
<?php foreach ($books as $b): $p = progress_for([$sid], (int)$b['id'])[$sid]; $ok = $p['progress'] >= $th; ?>
  <div class="kid-card" style="margin:0;text-align:center">
    <div style="font-size:2.6rem"><?= $ok ? '🏆' : '📘' ?></div>
    <strong><?= e($b['title']) ?></strong>
    <?= progress_bar($p['progress']) ?>
    <p class="small"><?= $ok ? 'Urime! Mund ta printosh certifikatën.' : 'Arrij ' . $th . '% për ta fituar certifikatën (tani ' . $p['progress'] . '%).' ?></p>
    <?php if ($ok): ?><a class="btn btn-primary" href="certificate.php?book=<?= (int)$b['id'] ?>" target="_blank">🖨️ Hap certifikatën</a><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php layout_end();
