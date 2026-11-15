<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$tid = (int)$u['id'];

if (is_post()) {
    csrf_check();
    $a = post('action');
    if ($a === 'refresh') {
        $n = generate_alerts($tid);
        flash('ok', $n ? "U gjetën $n njoftime të reja." : 'Nuk ka njoftime të reja. 👍');
    } elseif ($a === 'read') {
        q('UPDATE alerts SET read_at=NOW() WHERE id=? AND teacher_id=?', [posti('id'), $tid]);
    } elseif ($a === 'read_all') {
        q('UPDATE alerts SET read_at=NOW() WHERE teacher_id=? AND read_at IS NULL', [$tid]);
        flash('ok', 'Të gjitha u shënuan si të lexuara.');
    } elseif ($a === 'open') {
        $al = q1('SELECT * FROM alerts WHERE id=? AND teacher_id=?', [posti('id'), $tid]);
        if ($al) { q('UPDATE alerts SET read_at=COALESCE(read_at, NOW()) WHERE id=?', [$al['id']]); if ($al['link']) redirect($al['link']); }
    }
    redirect('teacher/alerts.php' . (get('all') ? '?all=1' : ''));
}

$showAll = (bool)get('all');
$rows = qa('SELECT a.*, s.avatar FROM alerts a LEFT JOIN users s ON s.id=a.student_id WHERE a.teacher_id=?' . ($showAll ? '' : ' AND a.read_at IS NULL') . ' ORDER BY a.created_at DESC LIMIT 300', [$tid]);
$kinds = alert_kinds();
$counts = [];
foreach (qa('SELECT kind, COUNT(*) n FROM alerts WHERE teacher_id=? AND read_at IS NULL GROUP BY kind', [$tid]) as $r) $counts[$r['kind']] = (int)$r['n'];

$actions = '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="refresh"><button class="btn">🔄 Kontrollo tani</button></form>';
if ($rows && !$showAll) $actions .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="read_all"><button class="btn">✔️ Shëno të gjitha si të lexuara</button></form>';
layout_start('Njoftimet', ['actions' => $actions]);
?>
<p class="muted">Sistemi kontrollon çdo ditë automatikisht: nxënësit që nuk kanë lexuar prej <strong><?= setting_int('alert_inactive_days') ?> ditësh</strong>, saktësinë nën <strong><?= setting_int('alert_low_score') ?>%</strong>, detyrat e vonuara dhe përgjigjet që presin vlerësim.</p>
<div class="stats">
  <?php foreach ($kinds as $k => [$ico, $lbl]): if ($k === 'badge') continue; ?>
    <div class="stat"><div class="lbl"><?= $ico ?> <?= e($lbl) ?></div><div class="val"><?= $counts[$k] ?? 0 ?></div></div>
  <?php endforeach; ?>
</div>
<div class="tabs"><a href="?" class="<?= $showAll ? '' : 'active' ?>">🔔 Të palexuara</a><a href="?all=1" class="<?= $showAll ? 'active' : '' ?>">📜 Të gjitha</a></div>
<div class="alert-list">
<?php foreach ($rows as $r): [$ico, $lbl] = $kinds[$r['kind']] ?? ['🔔', '']; ?>
  <div class="alert-item <?= $r['read_at'] ? 'read' : '' ?>">
    <span class="alert-ico" aria-hidden="true"><?= $ico ?></span>
    <div class="alert-body"><div class="small muted"><?= e($lbl) ?> · <?= e(ago($r['created_at'])) ?></div><div><?= e($r['message']) ?></div></div>
    <div class="row">
      <?php if ($r['link']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-primary">Shiko →</button></form><?php endif; ?>
      <?php if (!$r['read_at']): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" title="Shëno si të lexuar">✔️</button></form><?php endif; ?>
    </div>
  </div>
<?php endforeach; if (!$rows): ?><div class="card empty">🎉 Nuk ka njoftime <?= $showAll ? '' : 'të palexuara' ?>. Klasa juaj po shkon mirë!</div><?php endif; ?>
</div>
<?php layout_end();
