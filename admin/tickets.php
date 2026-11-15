<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    ticket_handle_post($u, 'admin/tickets.php');
}

if ($id = geti('id')) {
    $t = ticket_get($id);
    if (!$t) { flash('err', 'Tiketa nuk u gjet.'); redirect('admin/tickets.php'); }
    ticket_mark_read($u, $t);
    layout_start('Tiketa #' . $id, ['nav' => 'admin/tickets.php']);
    echo ticket_view_html($u, $t, url('admin/tickets.php'));
    layout_end();
    exit;
}

$tab = in_array(get('tab'), ['admin', 'students'], true) ? get('tab') : 'all';
$status = get('status', 'active');
$schoolId = geti('school');
$where = [$tab === 'all' ? '1=1' : ($tab === 'admin' ? "t.recipient_role='admin'" : "t.recipient_role='teacher'")];
if ($status === 'active') $where[] = "t.status<>'closed'";
elseif (in_array($status, ['open', 'in_progress', 'closed'], true)) $where[] = "t.status='" . $status . "'";
if ($schoolId) $where[] = 't.school_id=' . $schoolId;
$rows = qa(TICKET_LIST_SQL . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.updated_at DESC LIMIT 500');
$cAdmin = (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='admin' AND status<>'closed'");
$cStud = (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='teacher' AND status<>'closed'");
$cAll = (int)qv("SELECT COUNT(*) FROM tickets WHERE status<>'closed'");
$schools = qa('SELECT id, name FROM schools ORDER BY name');

layout_start('Tiketat & komentet');
?>
<div class="tabs">
  <a href="?tab=all" class="<?= $tab === 'all' ? 'active' : '' ?>">📋 Të gjitha tiketat (<?= $cAll ?>)</a>
  <a href="?tab=admin" class="<?= $tab === 'admin' ? 'active' : '' ?>">👩‍🏫→🛡️ Nga mësuesit për administratorin (<?= $cAdmin ?>)</a>
  <a href="?tab=students" class="<?= $tab === 'students' ? 'active' : '' ?>">🧒→👩‍🏫 Nga nxënësit për mësuesit (<?= $cStud ?>)</a>
</div>
<form method="get" class="row mb">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <select name="status" onchange="this.form.submit()" style="width:auto" aria-label="Statusi">
    <?php foreach (['active' => 'Aktive (të pa mbyllura)', 'open' => 'E hapur', 'in_progress' => 'Në proces', 'closed' => 'E mbyllur', 'all' => 'Të gjitha'] as $k => $l): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <select name="school" onchange="this.form.submit()" style="width:auto" aria-label="Shkolla"><option value="0">Të gjitha shkollat</option>
    <?php foreach ($schools as $s): ?><option value="<?= $s['id'] ?>" <?= $schoolId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
  </select>
  <input type="search" placeholder="🔎 Kërko…" data-filter="tickets-table" style="max-width:260px">
</form>
<?php if ($tab !== 'admin'): ?><p class="muted small">Administratori i sheh të gjitha tiketat — edhe ato të nxënësve për mësuesit. Ato trajtohen nga mësuesit, por administratori mund të shkruajë në to nëse duhet.</p><?php endif; ?>
<?= ticket_list_html($rows, url('admin/tickets.php'), $u, $tab !== 'admin') ?>
<?php layout_end();
