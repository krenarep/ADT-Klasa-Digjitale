<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$cid = geti('class');
$c = q1('SELECT c.*, s.name school_name FROM classes c JOIN schools s ON s.id=c.school_id WHERE c.id=?', [$cid]);
if (!$c || ($u['role'] === 'teacher' && (int)$c['teacher_id'] !== (int)$u['id'])) { http_response_code(404); exit('Klasa nuk u gjet.'); }
$rows = qa('SELECT s.* FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? AND s.active=1 ORDER BY s.full_name', [$cid]);
$loginUrl = (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : '') . url('login.php');

layout_start('Kartelat e hyrjes · ' . $c['name'], ['actions' => '<button class="btn btn-primary" onclick="window.print()">🖨️ Printo</button>']);
?>
<p class="no-print muted">Prisni kartelat dhe jepjani secilit nxënës. Hyrja: <strong><?= e($loginUrl) ?></strong></p>
<div class="cards-print">
<?php foreach ($rows as $s): ?>
  <div class="login-cardp">
    <h3><span class="big"><?= e($s['avatar']) ?></span> <?= e($s['full_name']) ?></h3>
    <div class="small muted"><?= e($c['school_name']) ?> · <?= e($c['name']) ?> · <?= e($loginUrl) ?></div>
    <table style="width:100%;margin-top:8px;font-size:1rem">
      <tr><td>🏫 Klasa:</td><td><strong><?= e($c['name']) ?></strong> · viti <?= (int)$c['grade'] ?><?= !empty($c['anon_no']) ? ' · nr. i klasës ' . sprintf('%02d', (int)$c['anon_no']) : '' ?></td></tr>
      <tr><td><?= ($s['gender'] ?? '') === 'F' ? '👧' : (($s['gender'] ?? '') === 'M' ? '👦' : '🧒') ?> Gjinia:</td><td><strong><?= e(gender_label($s['gender'] ?? null)) ?></strong></td></tr>
      <tr><td>🔑 Përdoruesi:</td><td><strong><?= e($s['username']) ?></strong></td></tr>
      <tr><td>🔒 Fjalëkalimi:</td><td><strong><?= e(dec_hint($s['plain_hint']) ?? '(ndryshuar — rivendoseni me 🔑)') ?></strong></td></tr>
      <?php if ($c['picture_login'] && picture_login_enabled()): ?>
      <tr><td>🏷️ Kodi i klasës:</td><td><strong style="letter-spacing:.15em"><?= e($c['class_code']) ?></strong></td></tr>
      <tr><td>👆 Në listë zgjidh:</td><td><strong style="font-size:1.25rem"><?= e($s['avatar']) ?> <?= e(anon_is_code($s['full_name']) ? 'Nr. ' . (int)substr($s['full_name'], strrpos($s['full_name'], '-') + 1) : $s['full_name']) ?></strong></td></tr>
      <tr><td>🖼️ Fotot e mia:</td><td><?= ($ps = dec_hint($s['picture_hint'])) ? picture_seq_html($ps) : '—' ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$rows): ?><p class="empty">Nuk ka nxënës në këtë klasë.</p><?php endif; ?>
<?php layout_end();
