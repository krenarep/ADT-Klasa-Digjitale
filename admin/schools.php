<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $a = post('action');
    $id = posti('id');
    $data = [post('name'), post('city') ?: null, post('address') ?: null, post('phone') ?: null];
    if (in_array($a, ['add', 'edit'], true) && $data[0] === '') { flash('err', 'Emri i shkollës është i detyrueshëm.'); redirect('admin/schools.php'); }
    if (in_array($a, ['add', 'edit'], true) && !valid_municipality((string)$data[1]) && !qv('SELECT 1 FROM schools WHERE id=? AND city=?', [$id, $data[1]])) { flash('err', 'Zgjidhni komunën e shkollës.'); redirect('admin/schools.php'); }
    if ($a === 'add') {
        q('INSERT INTO schools (name, city, address, phone) VALUES (?,?,?,?)', $data);
        audit('school.add', $data[0], (string)$data[1]);
        flash('ok', 'Shkolla "' . $data[0] . '" u regjistrua.');
    } elseif ($a === 'edit') {
        q('UPDATE schools SET name=?, city=?, address=?, phone=?, active=? WHERE id=?', [...$data, post('active') ? 1 : 0, $id]);
        audit('school.edit', $data[0], (string)$data[1]);
        flash('ok', 'Shkolla u përditësua.');
    } elseif ($a === 'delete') {
        $users = (int)qv('SELECT COUNT(*) FROM users WHERE school_id=?', [$id]);
        if ($users > 0) {
            flash('err', "Shkolla ka $users përdorues. Hiqni mësuesit dhe nxënësit më parë, ose çaktivizojeni shkollën.");
        } else {
            q('DELETE FROM schools WHERE id=?', [$id]);
            audit('school.delete', '#' . $id);
            flash('ok', 'Shkolla u fshi.');
        }
    }
    redirect('admin/schools.php');
}

$rows = qa("SELECT s.*,
  (SELECT COUNT(*) FROM users WHERE school_id=s.id AND role='teacher') teachers,
  (SELECT COUNT(*) FROM users WHERE school_id=s.id AND role='student') students,
  (SELECT COUNT(*) FROM classes WHERE school_id=s.id) classes
  FROM schools s ORDER BY s.name");

layout_start('Shkollat', ['actions' => '<button class="btn btn-primary" data-open="dlgAdd">＋ Regjistro shkollë</button>']);
?>
<div class="row mb"><input type="search" placeholder="🔎 Kërko shkollën…" data-filter="tbl" style="max-width:320px"></div>
<div class="table-wrap"><table class="t" id="tbl">
  <thead><tr><th>Shkolla</th><th>Komuna</th><th class="num">Mësues</th><th class="num">Klasa</th><th class="num">Nxënës</th><th>Statusi</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td><strong><?= e($s['name']) ?></strong><div class="small muted"><?= e($s['address']) ?> <?= e($s['phone']) ?></div></td>
      <td><?= e($s['city']) ?></td>
      <td class="num"><a href="teachers.php?school=<?= (int)$s['id'] ?>"><?= (int)$s['teachers'] ?></a></td>
      <td class="num"><?= (int)$s['classes'] ?></td>
      <td class="num"><a href="students.php?school=<?= (int)$s['id'] ?>"><?= (int)$s['students'] ?></a></td>
      <td><?= $s['active'] ? '<span class="badge badge-closed">Aktive</span>' : '<span class="badge badge-off">Joaktive</span>' ?></td>
      <td class="actions">
        <button class="btn btn-sm" data-open="dlgEdit" data-fill-id="<?= (int)$s['id'] ?>" data-fill-name="<?= e($s['name']) ?>" data-fill-city="<?= e($s['city']) ?>" data-fill-address="<?= e($s['address']) ?>" data-fill-phone="<?= e($s['phone']) ?>" data-fill-active="<?= (int)$s['active'] ?>">✏️ Ndrysho</button>
        <form method="post" class="inline" data-confirm="Ta fshij shkollën <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger">🗑️</button></form>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">🏫 Nuk ka shkolla ende. Regjistroni të parën.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php foreach (['dlgAdd' => ['add', 'Regjistro shkollë të re'], 'dlgEdit' => ['edit', 'Ndrysho shkollën']] as $dlg => [$act, $ttl]): ?>
<dialog id="<?= $dlg ?>"><div class="dlg-body">
  <div class="dlg-head"><h2><?= e($ttl) ?></h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id">
    <label>Emri i shkollës *<input name="name" required></label>
    <div class="grid-2"><label>Komuna *<select name="city" required><?= municipality_options(null) ?></select></label><label>Telefoni<input name="phone"></label></div>
    <label>Adresa<input name="address"></label>
    <?php if ($act === 'edit'): ?><label class="checkline"><input type="checkbox" name="active" value="1"> Shkolla është aktive</label><?php endif; ?>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<?php endforeach; ?>
<?php layout_end();
