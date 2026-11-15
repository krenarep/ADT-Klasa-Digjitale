<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];

$books = qa('SELECT DISTINCT b.* FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN class_students cs ON cs.class_id=cb.class_id WHERE cs.student_id=? ORDER BY b.title', [$sid]);
$prog = [];
foreach ($books as $b) $prog[(int)$b['id']] = progress_for([$sid], (int)$b['id'])[$sid];
$stars = (int)qv('SELECT COUNT(DISTINCT book_id, section_id) FROM quiz_attempts WHERE student_id=?', [$sid]);
$todaySec = (int)qv('SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=? AND day=CURDATE()', [$sid]);
$notes = qa('SELECT q.teacher_note, q.created_at, bp.title, bp.page_number FROM quiz_attempts q LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
             WHERE q.student_id=? AND q.teacher_note IS NOT NULL ORDER BY q.id DESC LIMIT 3', [$sid]);
$first = explode(' ', $u['full_name'])[0];
$badgeRes = compute_badges($sid);
$newBadges = array_values(array_filter(array_keys($badgeRes['earned']), fn($k) => !(int)($badgeRes['earned'][$k]['seen'] ?? 0)));
$assign = array_slice(array_filter(student_assignments($sid, true), fn($a) => $a['progress']['pct'] < 100), 0, 3);
$catB = badge_catalog();
$hour = (int)date('G');
$greet = $hour < 12 ? 'Mirëmëngjes' : ($hour < 18 ? 'Mirëdita' : 'Mirëmbrëma');

layout_start('Librat e mi');
?>
<section class="kid-hello">
  <span class="big" aria-hidden="true"><?= e($u['avatar'] ?: '🙂') ?></span>
  <div><h1><?= $greet ?>, <?= e($first) ?>!</h1><p>Sot ke mësuar <strong><?= e(fmt_duration($todaySec)) ?></strong>. Zgjidh një libër dhe fillo! 🚀</p></div>
  <a class="stars" href="achievements.php" title="Yje dhe distinktivë" style="text-decoration:none;color:inherit">⭐ <?= $stars ?> · 🏅 <?= count($badgeRes['earned']) ?></a>
</section>

<?php if ($newBadges): ?>
<section class="kid-card" style="border-color:#ff8a3d;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
  <span style="font-size:2.6rem" aria-hidden="true"><?= implode(' ', array_map(fn($k) => $catB[$k][0] ?? '🏅', array_slice($newBadges, 0, 4))) ?></span>
  <div style="flex:1"><strong>🎉 Urime! Fitove <?= count($newBadges) === 1 ? 'një distinktiv të ri' : count($newBadges) . ' distinktivë të rinj' ?>:</strong>
    <?= e(implode(', ', array_map(fn($k) => $catB[$k][1] ?? $k, $newBadges))) ?></div>
  <a class="btn btn-primary btn-lg" href="achievements.php">🏅 Shiko arritjet</a>
</section>
<?php endif; ?>

<?php if ($assign): ?>
<h2 style="font-size:1.4rem">📌 Detyrat e mia</h2>
<?php foreach ($assign as $a): $pp = $a['progress']; ?>
  <div class="assign-card <?= $pp['status'] === 'late' ? 'late' : '' ?>">
    <span style="font-size:2rem" aria-hidden="true"><?= $pp['status'] === 'late' ? '⏰' : '📖' ?></span>
    <div class="ac-main"><h3><?= e($a['title']) ?></h3><?= progress_bar($pp['pct']) ?>
      <div class="small">📚 <?= e($a['book_title']) ?> · <span class="due <?= $pp['status'] === 'late' ? 'late' : '' ?>"><?= e(due_label($a['due_date'])) ?></span></div></div>
    <a class="btn btn-primary" href="assignments.php">Hap →</a>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<h2 style="font-size:1.4rem">📚 Librat e mi</h2>
<div class="books-grid">
<?php foreach ($books as $b): $p = $prog[(int)$b['id']]; $cover = book_cover_url($b); ?>
  <div class="book-card">
    <a href="player.php?book=<?= (int)$b['id'] ?>" class="book-cover" style="<?= $cover ? 'background-image:url(\'' . e($cover) . '\')' : '' ?>" aria-label="Hap <?= e($b['title']) ?>"><?= $cover ? '' : '📗' ?></a>
    <div class="book-body">
      <h2><?= e($b['title']) ?></h2>
      <?= progress_bar($p['progress'], 'Progresi') ?>
      <div class="small">📖 <?= $p['pages'] ?>/<?= $p['page_total'] ?> faqe · ✍️ <?= $p['acts_done'] ?>/<?= $p['act_total'] ?> ushtrime</div>
      <a class="book-cta" href="player.php?book=<?= (int)$b['id'] ?>" style="text-decoration:none"><?= $p['pages'] ? '▶️ Vazhdo' : '🚀 Fillo' ?></a>
      <div class="row" style="justify-content:center">
        <button type="button" class="btn btn-sm offline-btn" data-offline-book="<?= (int)$b['id'] ?>" hidden>📥 Pa internet</button>
        <?php if ($p['progress'] >= (setting_int('cert_threshold') ?: 90)): ?><a class="btn btn-sm" href="certificate.php?book=<?= (int)$b['id'] ?>" target="_blank">🏆 Certifikata</a><?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$books): ?><div class="kid-card" style="text-align:center"><p style="font-size:3rem;margin:0">📭</p><p>Mësuesi nuk të ka dhënë ende asnjë libër. Së shpejti!</p></div><?php endif; ?>

<h2 style="font-size:1.4rem;margin-top:30px">🧭 Si punohet?</h2>
<div class="steps">
  <div class="step"><div class="e">📖</div><strong>1. Lexo</strong><br><span class="small">Lexo me kujdes udhëzimet në faqe.</span></div>
  <div class="step"><div class="e">🔊</div><strong>2. Dëgjo &amp; shiko</strong><br><span class="small">Përdor ⚙️ poshtë në libër për shkronja më të mëdha ose ngjyra.</span></div>
  <div class="step"><div class="e">✍️</div><strong>3. Plotëso</strong><br><span class="small">Shkruaj ose zgjidh përgjigjet, pastaj shtyp <b>Dërgo</b>.</span></div>
  <div class="step"><div class="e">➡️</div><strong>4. Vazhdo</strong><br><span class="small">Shko te faqja tjetër me shigjetën ›.</span></div>
</div>

<?php if ($notes): ?>
<section class="kid-card">
  <h2>💬 Mësuesi të ka shkruar</h2>
  <?php foreach ($notes as $n): ?><p>“<?= e($n['teacher_note']) ?>” <span class="small muted">— <?= $n['page_number'] ? 'faqja ' . (int)$n['page_number'] : e($n['title']) ?></span></p><?php endforeach; ?>
</section>
<?php endif; ?>

<section class="kid-card" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
  <span style="font-size:2.6rem" aria-hidden="true">🙋</span>
  <div style="flex:1"><strong>Ke ndonjë problem ose pyetje?</strong><br>Shkruaji mësuesit tënd.</div>
  <a class="btn btn-primary btn-lg" href="tickets.php?new=1">Më ndihmo</a>
</section>
<?php layout_end();
