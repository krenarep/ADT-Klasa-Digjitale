<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$schools = qa('SELECT id, name, city FROM schools ORDER BY name');
$schoolId = geti('school');
$muni = get('komuna');
if ($muni !== '' && !valid_municipality($muni)) $muni = '';
$qs = http_build_query(array_filter(['school' => $schoolId, 'komuna' => $muni]));

if (is_post()) {
    csrf_check();
    $a = post('action');
    $id = posti('id');
    try {
        if ($a === 'add') {
            if (!posti('school_id')) throw new RuntimeException('Zgjidhni shkollën.');
            create_teacher(post('full_name'), post('username'), (string)$_POST['password'], post('email'), post('phone'), post('municipality'), posti('school_id'), (int)$u['id']);
            audit('teacher.add', post('full_name'), post('municipality') . ' · ' . post('email'));
            flash('ok', 'Mësuesi "' . post('full_name') . '" u shtua.');
            unset($_SESSION['old_teacher']);
        } elseif ($a === 'edit') {
            if (trim((string)post('full_name')) === '') throw new RuntimeException('Emri është i detyrueshëm.');
            [$email, $phone, $m] = validate_teacher_contact(post('email'), post('phone'), post('municipality'), $id);
            q("UPDATE users SET full_name=?, email=?, phone=?, municipality=?, school_id=?, active=? WHERE id=? AND role='teacher'",
                [post('full_name'), $email, $phone, $m, posti('school_id'), post('active') ? 1 : 0, $id]);
            q('UPDATE classes SET school_id=? WHERE teacher_id=?', [posti('school_id'), $id]);
            if (($pw = (string)($_POST['password'] ?? '')) !== '') {
                if (strlen($pw) < 8) throw new RuntimeException('Fjalëkalimi duhet të ketë së paku 8 karaktere.');
                q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            }
            audit('teacher.edit', post('full_name'), $m . ' · ' . $email . ' · ' . $phone);
            flash('ok', 'Mësuesi u përditësua.');
        } elseif ($a === 'delete') {
            $nm = (string)qv('SELECT full_name FROM users WHERE id=?', [$id]);
            q("DELETE FROM users WHERE id=? AND role='teacher'", [$id]);
            audit('teacher.delete', $nm . ' #' . $id);
            flash('ok', 'Mësuesi u hoq. Klasat e tij u fshinë (nxënësit mbeten në sistem pa klasë).');
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
        if ($a === 'add') {
            $_SESSION['old_teacher'] = array_diff_key($_POST, ['password' => 1, '_csrf' => 1]);
            redirect('admin/teachers.php?' . ($qs ? $qs . '&' : '') . 'reopen=add');
        }
    }
    redirect('admin/teachers.php' . ($qs ? '?' . $qs : ''));
}

$where = ["t.role='teacher'"];
$params = [];
if ($schoolId) { $where[] = 't.school_id = ?'; $params[] = $schoolId; }
if ($muni) { $where[] = 't.municipality = ?'; $params[] = $muni; }
$rows = qa("SELECT t.*, s.name school_name,
   (SELECT COUNT(*) FROM classes WHERE teacher_id=t.id) classes,
   (SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=t.id) students,
   (SELECT COUNT(*) FROM tickets WHERE assigned_to=t.id AND status<>'closed') open_t,
   (SELECT COUNT(*) FROM books WHERE uploaded_by=t.id) books
  FROM users t LEFT JOIN schools s ON s.id=t.school_id
  WHERE " . implode(' AND ', $where) . ' ORDER BY t.municipality, s.name, t.full_name', $params);

/* ---------- Eksport CSV ---------- */
if (get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    audit('export', 'Lista e mësuesve (CSV)', count($rows) . ' rreshta');
    header('Content-Disposition: attachment; filename="mesuesit_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Emri dhe mbiemri', 'Komuna', 'Shkolla', 'Email', 'Telefoni', 'Përdoruesi', 'Klasa', 'Nxënës', 'Aktiv', 'Hyrja e fundit'], ';', '"', '\\');
    foreach ($rows as $t) {
        fputcsv($out, [$t['full_name'], $t['municipality'], $t['school_name'], $t['email'], $t['phone'], $t['username'], $t['classes'], $t['students'],
            $t['active'] ? 'Po' : 'Jo', $t['last_login']], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$incomplete = count(array_filter($rows, fn($t) => !$t['email'] || !$t['phone'] || !$t['municipality']));
$old = $_SESSION['old_teacher'] ?? [];
unset($_SESSION['old_teacher']);

$filter = '<form method="get" class="row"><select name="komuna" onchange="this.form.submit()" aria-label="Komuna">' . municipality_options($muni, true, 'Të gjitha komunat') . '</select>';
$filter .= '<select name="school" onchange="this.form.submit()" aria-label="Shkolla"><option value="0">Të gjitha shkollat</option>';
foreach ($schools as $s) $filter .= '<option value="' . $s['id'] . '"' . ($schoolId === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
$filter .= '</select></form><a class="btn" href="?' . e(($qs ? $qs . '&' : '') . 'export=csv') . '">⬇️ CSV</a>';

layout_start('Mësuesit', ['actions' => $filter . ($schools ? '<button class="btn btn-primary" data-open="dlgAdd">＋ Shto mësues</button>' : '')]);
if (!$schools) echo '<div class="flash flash-info">ℹ️ Regjistroni së pari një <a href="schools.php">shkollë</a>.</div>';
if ($incomplete) echo '<div class="flash flash-err">⚠️ ' . $incomplete . ' mësues nuk kanë të plotësuar komunën, email-in ose telefonin. Shtypni ✏️ te secili për t\'i plotësuar.</div>';
?>
<div class="row-between mb"><input type="search" placeholder="🔎 Kërko emër, email, telefon…" data-filter="tbl" style="max-width:320px"><span class="muted small"><?= count($rows) ?> mësues</span></div>
<div class="table-wrap"><table class="t" id="tbl">
  <thead><tr><th>Mësuesi</th><th>Komuna</th><th>Shkolla</th><th>Email</th><th>Telefoni</th><th>Përdoruesi</th><th class="num">Klasa</th><th class="num">Nxënës</th><th class="num">Tiketa</th><th>Hyrja e fundit</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): $miss = !$t['email'] || !$t['phone'] || !$t['municipality']; ?>
    <tr>
      <td><strong><?= e($t['full_name']) ?></strong> <?= $t['active'] ? '' : '<span class="badge badge-off">Joaktiv</span>' ?><?= $miss ? ' <span class="badge badge-open" title="Mungojnë të dhëna kontakti">⚠️ Plotëso</span>' : '' ?></td>
      <td><?= $t['municipality'] ? e($t['municipality']) : '<span class="muted">—</span>' ?></td>
      <td class="small"><?= e($t['school_name'] ?? '—') ?></td>
      <td class="small"><?= $t['email'] ? '<a href="mailto:' . e($t['email']) . '">' . e($t['email']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td class="small" style="white-space:nowrap"><?= $t['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $t['phone'])) . '">' . e($t['phone']) . '</a>' : '<span class="muted">—</span>' ?></td>
      <td><code><?= e($t['username']) ?></code></td>
      <td class="num"><?= (int)$t['classes'] ?></td>
      <td class="num"><a href="students.php?teacher=<?= (int)$t['id'] ?>"><?= (int)$t['students'] ?></a></td>
      <td class="num"><?= (int)$t['open_t'] ?></td>
      <td class="small"><?= e(ago($t['last_login'])) ?></td>
      <td class="actions">
        <button class="btn btn-sm" data-open="dlgEdit" data-fill-id="<?= (int)$t['id'] ?>" data-fill-full-name="<?= e($t['full_name']) ?>" data-fill-email="<?= e($t['email']) ?>"
          data-fill-phone="<?= e($t['phone']) ?>" data-fill-municipality="<?= e($t['municipality']) ?>" data-fill-school-id="<?= (int)$t['school_id'] ?>" data-fill-active="<?= (int)$t['active'] ?>" title="Ndrysho">✏️</button>
        <form method="post" class="inline" data-confirm="Ta heq mësuesin <?= e($t['full_name']) ?>? Klasat e tij do të fshihen."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-danger" title="Fshi">🗑️</button></form>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="empty">👩‍🏫 Nuk ka mësues për këtë filtër.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php
$schoolOpts = function (?int $sel) use ($schools) {
    $h = '';
    foreach ($schools as $s) $h .= '<option value="' . $s['id'] . '" data-komuna="' . e($s['city']) . '"' . ($sel === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . ($s['city'] ? ' (' . e($s['city']) . ')' : '') . '</option>';
    return $h;
};
$contactFields = function (array $v) {
    return '<label>Komuna *<select name="municipality" required>' . municipality_options($v['municipality'] ?? null) . '</select></label>
    <div class="grid-2"><label>Email *<input type="email" name="email" required value="' . e($v['email'] ?? '') . '" placeholder="emri@shkolla.edu"></label>
    <label>Telefoni *<input type="tel" name="phone" required value="' . e($v['phone'] ?? '') . '" placeholder="+383 44 123 456" pattern="[0-9+\\s\\-\\/()]{8,22}"></label></div>';
};
?>
<dialog id="dlgAdd"><div class="dlg-body">
  <div class="dlg-head"><h2>Shto mësues</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Emri dhe mbiemri *<input name="full_name" required value="<?= e($old['full_name'] ?? '') ?>"></label>
    <label>Shkolla *<select name="school_id" required data-komuna-source><option value="">— zgjidh —</option><?= $schoolOpts((int)($old['school_id'] ?? $schoolId)) ?></select></label>
    <?= $contactFields($old + ['municipality' => $muni ?: null]) ?>
    <div class="grid-2"><label>Përdoruesi *<input name="username" required pattern="[A-Za-z0-9._\-]+" placeholder="p.sh. arta.krasniqi" value="<?= e($old['username'] ?? '') ?>"></label>
    <label>Fjalëkalimi fillestar * <span class="help">min. 8</span><input name="password" required minlength="8" value="<?= e(make_kid_password() . 'Ms!') ?>"></label></div>
    <p class="help" style="margin:0">* Të gjitha fushat janë të detyrueshme.</p>
    <button class="btn btn-primary">Shto</button>
  </form>
</div></dialog>
<dialog id="dlgEdit"><div class="dlg-body">
  <div class="dlg-head"><h2>Ndrysho mësuesin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id">
    <label>Emri dhe mbiemri *<input name="full_name" required></label>
    <label>Shkolla *<select name="school_id" required><?= $schoolOpts(null) ?></select></label>
    <?= $contactFields([]) ?>
    <label>Fjalëkalim i ri <span class="help">lëre bosh për ta mbajtur</span><input name="password" minlength="8"></label>
    <label class="checkline"><input type="checkbox" name="active" value="1"> Llogaria aktive</label>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<script>
  // Kur zgjidhet shkolla, komuna plotësohet automatikisht (nëse është bosh)
  document.querySelectorAll('[data-komuna-source]').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var k = sel.selectedOptions[0] && sel.selectedOptions[0].dataset.komuna;
      var m = sel.form.querySelector('[name="municipality"]');
      if (k && m && !m.value && Array.from(m.options).some(function (o) { return o.value === k; })) m.value = k;
    });
  });
  <?php if (get('reopen') === 'add'): ?>document.addEventListener('DOMContentLoaded', function () { document.getElementById('dlgAdd').showModal(); });<?php endif; ?>
</script>
<?php layout_end();
