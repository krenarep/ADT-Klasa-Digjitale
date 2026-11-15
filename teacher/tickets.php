<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');

if (is_post()) {
    csrf_check();
    if (post('action') === 'new') {
        try {
            $id = ticket_create($u, 'admin', null, post('kind'), post('category'), post('subject'), (string)post('message'));
            flash('ok', 'Tiketa #' . $id . ' u dërgua te administratori.');
            redirect('teacher/tickets.php?id=' . $id);
        } catch (Throwable $ex) {
            flash('err', $ex->getMessage());
            redirect('teacher/tickets.php?tab=mine');
        }
    }
    ticket_handle_post($u, 'teacher/tickets.php');
}

if ($id = geti('id')) {
    $t = ticket_get($id);
    if (!$t || !ticket_can_view($u, $t)) { flash('err', 'Tiketa nuk u gjet.'); redirect('teacher/tickets.php'); }
    ticket_mark_read($u, $t);
    layout_start('Tiketa #' . $id, ['nav' => 'teacher/tickets.php']);
    echo ticket_view_html($u, $t, url('teacher/tickets.php' . ($t['recipient_role'] === 'admin' ? '?tab=mine' : '')), true);
    layout_end();
    exit;
}

$tab = get('tab', 'students') === 'mine' ? 'mine' : 'students';
$status = get('status', 'active');
$where = $tab === 'students' ? ["t.recipient_role='teacher' AND t.assigned_to=" . (int)$u['id']] : ['t.created_by=' . (int)$u['id']];
if ($status === 'active') $where[] = "t.status<>'closed'";
elseif (in_array($status, ['open', 'in_progress', 'closed'], true)) $where[] = "t.status='$status'";
$rows = qa(TICKET_LIST_SQL . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY t.updated_at DESC LIMIT 300');
$cS = (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='teacher' AND assigned_to=? AND status<>'closed'", [$u['id']]);
$cM = (int)qv("SELECT COUNT(*) FROM tickets WHERE created_by=? AND status<>'closed'", [$u['id']]);

layout_start('Tiketat', ['actions' => '<button class="btn btn-primary" data-open="dlgNew">＋ Tiketë për administratorin</button>']);
?>
<div class="tabs">
  <a href="?tab=students" class="<?= $tab === 'students' ? 'active' : '' ?>">🧒 Nga nxënësit e mi (<?= $cS ?>)</a>
  <a href="?tab=mine" class="<?= $tab === 'mine' ? 'active' : '' ?>">🛡️ Të miat te administratori (<?= $cM ?>)</a>
</div>
<form method="get" class="row mb"><input type="hidden" name="tab" value="<?= $tab ?>">
  <select name="status" onchange="this.form.submit()" style="width:auto" aria-label="Statusi">
    <?php foreach (['active' => 'Aktive', 'open' => 'E hapur', 'in_progress' => 'Në proces', 'closed' => 'E mbyllur', 'all' => 'Të gjitha'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <input type="search" placeholder="🔎 Kërko…" data-filter="tickets-table" style="max-width:260px">
</form>
<?= ticket_list_html($rows, url('teacher/tickets.php'), $u) ?>

<dialog id="dlgNew"><div class="dlg-body">
  <div class="dlg-head"><h2>Tiketë e re për administratorin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="new">
    <div class="grid-2">
      <label>Lloji<select name="kind"><?php foreach (ticket_kinds() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Kategoria<select name="category"><?php foreach (ticket_categories() as $k => [$i, $l]): ?><option value="<?= $k ?>"><?= $i ?> <?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Tema *<input name="subject" required maxlength="200"></label>
    <label>Përshkrimi *<textarea name="message" required rows="6"></textarea></label>
    <button class="btn btn-primary">Dërgo</button>
  </form>
</div></dialog>
<?php layout_end();
