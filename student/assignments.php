<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];
$list = student_assignments($sid);
$open = array_filter($list, fn($a) => $a['progress']['pct'] < 100);
$doneL = array_filter($list, fn($a) => $a['progress']['pct'] >= 100);

/** faqja e parë e papërfunduar e detyrës */
function next_section(array $a, int $sid): string {
    foreach (assignment_sections($a) as $s) {
        $seen = qv('SELECT 1 FROM page_views WHERE student_id=? AND book_id=? AND section_id=?', [$sid, $a['book_id'], $s['section_id']]);
        $sub = !(int)$s['is_activity'] || qv('SELECT 1 FROM quiz_attempts WHERE student_id=? AND book_id=? AND section_id=?', [$sid, $a['book_id'], $s['section_id']]);
        if (!$seen || !$sub) return $s['section_id'];
    }
    return $a['first_section'];
}

layout_start('Detyrat e mia');
?>
<section class="kid-hello" style="background:linear-gradient(120deg,#e3f6ec,#fff1c9)">
  <span class="big" aria-hidden="true">📌</span>
  <div><h1>Detyrat e mia</h1><p><?= count($open) ? 'Ke <strong>' . count($open) . '</strong> detyrë/a për të bërë. Fillo me atë që ka afatin më afër!' : 'Bravo! I ke kryer të gjitha detyrat. 🎉' ?></p></div>
</section>

<?php foreach ([$open, $doneL] as $i => $group): if (!$group) continue; ?>
  <h2 style="font-size:1.3rem"><?= $i === 0 ? '✏️ Për të bërë' : '✅ Të kryera' ?></h2>
  <?php foreach ($group as $a): $p = $a['progress']; $late = $p['status'] === 'late'; ?>
    <div class="assign-card <?= $p['status'] === 'done' ? 'done' : ($late ? 'late' : '') ?>">
      <span style="font-size:2.4rem" aria-hidden="true"><?= $p['status'] === 'done' ? '✅' : ($late ? '⏰' : '📖') ?></span>
      <div class="ac-main">
        <h3><?= e($a['title']) ?></h3>
        <div class="small muted">📚 <?= e($a['book_title']) ?> · 👩‍🏫 <?= e($a['teacher']) ?></div>
        <?= progress_bar($p['pct'], 'Progresi i detyrës') ?>
        <div class="small">📖 <?= $p['pages'] ?>/<?= $p['page_total'] ?> faqe<?= $p['act_total'] ? ' · ✍️ ' . $p['acts'] . '/' . $p['act_total'] . ' ushtrime' : '' ?> · <span class="due <?= $late ? 'late' : '' ?>"><?= e(due_label($a['due_date'])) ?></span></div>
        <?php if ($a['instructions']): ?><p class="small" style="margin:6px 0 0">💬 <?= e($a['instructions']) ?></p><?php endif; ?>
      </div>
      <?php if ($p['status'] !== 'done'): ?>
        <a class="btn btn-primary btn-lg" href="player.php?book=<?= (int)$a['book_id'] ?>&page=<?= e(urlencode(next_section($a, $sid))) ?>"><?= $p['pct'] ? '▶️ Vazhdo' : '🚀 Fillo' ?></a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php if (!$list): ?><div class="kid-card" style="text-align:center"><p style="font-size:3rem;margin:0">🌈</p><p>Nuk ke asnjë detyrë tani. Mund të lexosh lirisht librat e tu!</p><a class="btn btn-primary" href="index.php">📚 Librat e mi</a></div><?php endif; ?>
<?php layout_end();
