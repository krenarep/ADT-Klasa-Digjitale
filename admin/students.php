<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$schoolId = geti('school');
$teacherId = geti('teacher');
$classId = geti('class');
$qs = http_build_query(array_filter(['school' => $schoolId, 'teacher' => $teacherId, 'class' => $classId, 'gjinia' => get('gjinia'), 'nv' => get('nv')], fn($v) => $v !== '' && $v !== 0));

$classes = qa("SELECT c.id, c.name, c.grade, c.school_id, s.name school_name, t.full_name teacher_name, c.teacher_id
  FROM classes c JOIN schools s ON s.id=c.school_id JOIN users t ON t.id=c.teacher_id ORDER BY s.name, t.full_name, c.name");

/** Klasa ekzistuese (id) ose klasë e re për mësuesin ("new:<teacherId>") */
function admin_resolve_class(string $sel): ?array {
    if (str_starts_with($sel, 'new:')) {
        $t = q1("SELECT id, full_name, school_id FROM users WHERE role='teacher' AND id=?", [(int)substr($sel, 4)]);
        if (!$t) throw new RuntimeException('Mësuesi nuk u gjet.');
        if (!$t['school_id']) throw new RuntimeException('Mësuesi ' . $t['full_name'] . ' nuk ka shkollë. Caktojeni shkollën te faqja Mësuesit.');
        $grade = max(1, min(5, posti('new_grade', 3)));
        $name = trim((string)post('new_class_name')) ?: ('Klasa ' . $grade);
        $ex = q1('SELECT * FROM classes WHERE teacher_id=? AND name=?', [$t['id'], $name]);
        if ($ex) return $ex;
        q('INSERT INTO classes (school_id, teacher_id, name, grade, class_code, picture_login) VALUES (?,?,?,?,?,1)',
            [$t['school_id'], $t['id'], $name, $grade, make_class_code()]);
        $c = q1('SELECT * FROM classes WHERE id=?', [(int)db()->lastInsertId()]);
        auto_assign_class_books((int)$c['id']);
        audit('class.add', $name, $t['full_name']);
        return $c;
    }
    return q1('SELECT * FROM classes WHERE id=?', [(int)$sel]) ?: null;
}

/** Lista e plotë: të gjitha shkollat → të gjithë mësuesit → klasat e tyre (+ "Klasë e re") */
function admin_class_options(int $selected = 0): string {
    $schools = qa('SELECT id, name, city FROM schools ORDER BY name');
    $teachers = qa("SELECT id, full_name, school_id FROM users WHERE role='teacher' ORDER BY full_name");
    $cls = qa('SELECT id, name, grade, teacher_id FROM classes ORDER BY grade, name');
    $byT = []; foreach ($cls as $c) $byT[(int)$c['teacher_id']][] = $c;
    $byS = []; foreach ($teachers as $t) $byS[(int)$t['school_id']][] = $t;
    $h = '';
    $groups = array_map(fn($s) => [(int)$s['id'], $s['name'] . ($s['city'] ? ' (' . $s['city'] . ')' : '')], $schools);
    if (!empty($byS[0])) $groups[] = [0, 'Pa shkollë'];
    foreach ($groups as [$sid, $label]) {
        $h .= '<optgroup label="🏫 ' . e($label) . '">';
        if (empty($byS[$sid])) { $h .= '<option disabled>— nuk ka mësues në këtë shkollë —</option>'; }
        foreach ($byS[$sid] ?? [] as $t) {
            foreach ($byT[(int)$t['id']] ?? [] as $c)
                $h .= '<option value="' . (int)$c['id'] . '"' . ($selected === (int)$c['id'] ? ' selected' : '') . '>' . e($t['full_name'] . ' · ' . $c['name'] . ' (kl. ' . (int)$c['grade'] . ')') . '</option>';
            $h .= '<option value="new:' . (int)$t['id'] . '"' . ($sid ? '' : ' disabled') . '>' . e($t['full_name'] . ' · ➕ klasë e re') . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
}
function admin_new_class_fields(): string {
    return '<div class="grid-2" data-newclass hidden><label>Emri i klasës së re<input name="new_class_name" placeholder="p.sh. V/2"></label>'
        . '<label>Klasa (viti)<select name="new_grade">' . implode('', array_map(fn($g) => '<option value="' . $g . '"' . ($g === 5 ? ' selected' : '') . '>' . $g . '</option>', [1, 2, 3, 4, 5])) . '</select></label></div>';
}

if (get('export') === 'last') export_last_import_csv();

if (is_post()) {
    csrf_check();
    $a = post('action');
    $id = posti('id');
    try {
        if ($a === 'import') {
            $sel = (string)post('class_id');
            if (str_starts_with($sel, 'new:')) $sel = (string)admin_resolve_class($sel)['id'];
            $byId = [];
            foreach (qa('SELECT * FROM classes') as $c) $byId[(int)$c['id']] = $c;
            handle_student_import($byId[(int)$sel] ?? null, $byId, (int)$u['id']);
        } elseif ($a === 'add') {
            $c = admin_resolve_class((string)post('class_id'));
            if (!$c) throw new RuntimeException('Zgjidhni klasën (mësuesin) ku do të regjistrohet nxënësi.');
            $r = create_student(post('full_name'), (int)$c['school_id'], (int)$c['grade'], (int)$c['id'], (int)$u['id'], post('username') ?: null, post('password') ?: null, null, demographics_from_post());
            audit('student.add', $r['code'], $c['name']);
            $_SESSION['import_result'] = ['created' => [$r + ['full_name' => $r['real_name'], 'class' => $c['name'], 'class_code' => $c['class_code']]], 'errors' => []];
            flash('ok', "Nxënësi u shtua si {$r['code']}: përdoruesi {$r['username']}, fjalëkalimi {$r['password']}.");
        } elseif ($a === 'enroll') {
            $c = admin_resolve_class((string)post('class_id'));
            if (!$c) throw new RuntimeException('Klasa nuk u gjet.');
            if (post('move')) q('DELETE FROM class_students WHERE student_id=?', [$id]);
            q('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?,?)', [$c['id'], $id]);
            q("UPDATE users SET school_id=?, grade=? WHERE id=? AND role='student'", [$c['school_id'], $c['grade'], $id]);
            audit('student.enroll', '#' . $id, $c['name']);
            flash('ok', 'Nxënësi u regjistrua në klasën ' . $c['name'] . '.');
        } elseif ($a === 'unenroll') {
            q('DELETE FROM class_students WHERE student_id=? AND class_id=?', [$id, posti('class_id')]);
            audit('student.unenroll', '#' . $id, 'klasa #' . posti('class_id'));
            flash('ok', 'Nxënësi u hoq nga klasa.');
        } elseif ($a === 'edit') {
            if (!anon_enabled()) {
                if (trim((string)post('full_name')) === '') throw new RuntimeException('Emri është i detyrueshëm.');
                q("UPDATE users SET full_name=? WHERE id=? AND role='student'", [post('full_name'), $id]);
            }
            save_demographics($id, demographics_from_post());
            audit('student.edit', (string)qv('SELECT full_name FROM users WHERE id=?', [$id]));
            flash('ok', 'Të dhënat e nxënësit u ruajtën.');
        } elseif ($a === 'toggle') {
            q("UPDATE users SET active = 1 - active WHERE id=? AND role='student'", [$id]);
            flash('ok', 'Statusi i llogarisë u ndryshua.');
        } elseif ($a === 'reset') {
            $r = reset_student_credentials($id);
            audit('student.reset', '#' . $id);
            flash('ok', "Fjalëkalimi i ri: {$r['password']} (dhe fotot e reja për hyrjen me foto).");
        } elseif ($a === 'delete') {
            $nm = (string)qv('SELECT full_name FROM users WHERE id=?', [$id]);
            q("DELETE FROM users WHERE id=? AND role='student'", [$id]);
            audit('student.delete', $nm . ' #' . $id);
            flash('ok', 'Nxënësi u fshi nga sistemi bashkë me të dhënat e progresit.');
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/students.php' . ($qs ? '?' . $qs : ''));
}

$where = ["s.role='student'"];
$params = [];
if ($schoolId) { $where[] = 's.school_id = ?'; $params[] = $schoolId; }
$gf = get('gjinia');
if (isset(genders()[$gf])) { $where[] = 's.gender = ?'; $params[] = $gf; } else $gf = '';
$nf = get('nv');
if ($nf === '1') $where[] = 's.special_needs = 1'; elseif ($nf === '0') $where[] = '(s.special_needs = 0 OR s.special_needs IS NULL)'; else $nf = '';
if ($teacherId) { $where[] = 'EXISTS (SELECT 1 FROM class_students x JOIN classes y ON y.id=x.class_id WHERE x.student_id=s.id AND y.teacher_id=?)'; $params[] = $teacherId; }
if ($classId) { $where[] = 'EXISTS (SELECT 1 FROM class_students x WHERE x.student_id=s.id AND x.class_id=?)'; $params[] = $classId; }
$rows = qa("SELECT s.*, sc.name school_name,
   (SELECT GROUP_CONCAT(CONCAT(c.id, '|', c.name, '|', t.full_name) SEPARATOR ';;') FROM class_students cs JOIN classes c ON c.id=cs.class_id JOIN users t ON t.id=c.teacher_id WHERE cs.student_id=s.id) cls,
   (SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=s.id) secs,
   (SELECT MAX(last_seen) FROM page_views WHERE student_id=s.id) last_seen,
   (SELECT COUNT(*) FROM quiz_attempts WHERE student_id=s.id) attempts
  FROM users s LEFT JOIN schools sc ON sc.id=s.school_id WHERE " . implode(' AND ', $where) . ' ORDER BY sc.name, s.full_name LIMIT 2000', $params);

$schools = qa('SELECT id, name FROM schools ORDER BY name');
$teachers = qa("SELECT id, full_name, school_id FROM users WHERE role='teacher' " . ($schoolId ? 'AND school_id=' . $schoolId : '') . ' ORDER BY full_name');
$classOpts = '';
foreach ($classes as $c) $classOpts .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>' . e($c['school_name'] . ' · ' . $c['teacher_name'] . ' · ' . $c['name']) . '</option>';

$filter = '<form method="get" class="row">';
$filter .= '<select name="school" onchange="this.form.submit()" aria-label="Shkolla"><option value="0">Të gjitha shkollat</option>';
foreach ($schools as $s) $filter .= '<option value="' . $s['id'] . '"' . ($schoolId === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
$filter .= '</select><select name="teacher" onchange="this.form.submit()" aria-label="Mësuesi"><option value="0">Të gjithë mësuesit</option>';
foreach ($teachers as $t) $filter .= '<option value="' . $t['id'] . '"' . ($teacherId === (int)$t['id'] ? ' selected' : '') . '>' . e($t['full_name']) . '</option>';
$filter .= '</select><select name="class" onchange="this.form.submit()" aria-label="Klasa"><option value="0">Të gjitha klasat</option>';
foreach ($classes as $c) {
    if (($schoolId && (int)$c['school_id'] !== $schoolId) || ($teacherId && (int)$c['teacher_id'] !== $teacherId)) continue;
    $filter .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>' . e($c['name'] . ' · ' . $c['teacher_name']) . '</option>';
}
$filter .= '</select><select name="gjinia" onchange="this.form.submit()" aria-label="Gjinia">' . select_options(genders(), $gf, 'Vajza & djem') . '</select>';
$filter .= '<select name="nv" onchange="this.form.submit()" aria-label="Nevoja të veçanta">' . select_options(['1' => 'Me nevoja të veçanta', '0' => 'Pa nevoja të veçanta'], $nf, 'Të gjithë', true) . '</select></form>';

layout_start('Nxënësit', ['actions' => $filter . '<button class="btn" data-open="dlgImport">📥 Ngarko listë</button><button class="btn btn-primary" data-open="dlgAdd">＋ Shto nxënës</button>']);
if (!qv("SELECT COUNT(*) FROM users WHERE role='teacher'")) echo '<div class="flash flash-info">ℹ️ Nxënësit regjistrohen në klasat e mësuesve. Shtoni së pari një <a href="teachers.php">mësues</a>.</div>';
elseif (!$classes) echo '<div class="flash flash-info">ℹ️ Mësuesit ende nuk kanë klasa. Te „Shto nxënës” ose „Ngarko listë” zgjidhni mësuesin → <strong>➕ klasë e re</strong>, dhe klasa krijohet automatikisht.</div>';
?>
<?= import_result_html(url('admin/students.php?export=last')) ?>
<?php if ($classId): ?><p><a class="btn btn-sm" href="../teacher/cards.php?class=<?= $classId ?>" target="_blank">🖨️ Kartelat e hyrjes për këtë klasë</a></p><?php endif; ?>
<div class="row-between mb"><input type="search" placeholder="🔎 Kërko nxënësin…" data-filter="tbl" style="max-width:320px"><span class="muted small"><?= count($rows) ?> nxënës</span></div>
<div class="table-wrap"><table class="t" id="tbl">
  <thead><tr><th>Nxënësi</th><th>Përdoruesi</th><th>Shkolla</th><th>Klasa · Mësuesi</th><th class="num">Koha</th><th class="num">Tentativa</th><th>Aktiv</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td><span aria-hidden="true"><?= e($s['avatar']) ?></span> <a href="../teacher/student.php?id=<?= (int)$s['id'] ?>"><strong><?= e($s['full_name']) ?></strong></a> <?= $s['active'] ? '' : '<span class="badge badge-off">Joaktiv</span>' ?><div class="small muted"><?= student_badges($s) ?>Klasa <?= (int)$s['grade'] ?: '—' ?><?= $s['mother_tongue'] ? ' · ' . e($s['mother_tongue']) : '' ?></div></td>
      <td><code><?= e($s['username']) ?></code></td>
      <td class="small"><?= e($s['school_name'] ?? '—') ?></td>
      <td class="small"><?php foreach (array_filter(explode(';;', (string)$s['cls'])) as $c): [$cid, $cn, $tn] = explode('|', $c) + ['', '', '']; ?>
        <form method="post" class="inline" data-confirm="Ta heq nga klasa <?= e($cn) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="unenroll"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="class_id" value="<?= (int)$cid ?>">
        <span class="badge"><?= e($cn) ?> · <?= e($tn) ?> <button class="btn-ghost" style="border:0;cursor:pointer;padding:0 2px" aria-label="Hiq nga klasa">✕</button></span></form>
      <?php endforeach; if (!$s['cls']) echo '<span class="muted">pa klasë</span>'; ?></td>
      <td class="num"><?= e(fmt_duration((int)$s['secs'])) ?></td>
      <td class="num"><?= (int)$s['attempts'] ?></td>
      <td class="small"><?= e(ago($s['last_seen'])) ?></td>
      <td class="actions">
        <button class="btn btn-sm" data-open="dlgEdit" data-fill-id="<?= (int)$s['id'] ?>" data-fill-full-name="<?= e($s['full_name']) ?>" data-fill-gender="<?= e($s['gender']) ?>" data-fill-mother-tongue="<?= e($s['mother_tongue']) ?>" data-fill-special-needs="<?= $s['special_needs'] === null ? '' : (int)$s['special_needs'] ?>" data-fill-special-needs-type="<?= e($s['special_needs_type']) ?>" data-fill-sen-consent="<?= !empty($s['sen_consent_at']) ? 1 : 0 ?>" data-fill-sen-consent-by="<?= e($s['sen_consent_by'] ?? '') ?>" title="Ndrysho të dhënat">✏️</button>
        <button class="btn btn-sm" data-open="dlgEnroll" data-fill-id="<?= (int)$s['id'] ?>" title="Regjistro në klasë">🏷️</button>
        <form method="post" class="inline" data-confirm="Të gjenerohet fjalëkalim i ri për <?= e($s['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm" title="Fjalëkalim i ri">🔑</button></form>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm" title="<?= $s['active'] ? 'Çaktivizo' : 'Aktivizo' ?>"><?= $s['active'] ? '⏸️' : '▶️' ?></button></form>
        <form method="post" class="inline" data-confirm="Ta fshij përgjithmonë nxënësin <?= e($s['full_name']) ?> dhe të gjithë progresin e tij?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-danger" title="Fshi">🗑️</button></form>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">🧒 Nuk ka nxënës për këtë filtër.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<dialog id="dlgAdd"><div class="dlg-body">
  <div class="dlg-head"><h2>Shto nxënës</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label>Emri dhe mbiemri *<input name="full_name" required></label>
    <label>Shkolla · Mësuesi · Klasa *<select name="class_id" required data-classpick><option value="">— zgjidh —</option><?= admin_class_options($classId) ?></select></label>
    <?= admin_new_class_fields() ?>
    <div class="grid-2"><label>Përdoruesi <span class="help">bosh = automatik</span><input name="username"></label>
    <label>Fjalëkalimi <span class="help">bosh = automatik</span><input name="password"></label></div>
    <?= demographics_fields_html() ?>
    <p class="help">Fotot për hyrjen me fotografi gjenerohen automatikisht. Mësuesi mund t'i printojë kartelat e hyrjes.</p>
    <button class="btn btn-primary">Shto</button>
  </form>
</div></dialog>
<dialog id="dlgEdit"><div class="dlg-body">
  <div class="dlg-head"><h2>Ndrysho nxënësin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id">
    <label><?= anon_enabled() ? 'Kodi i nxënësit' : 'Emri dhe mbiemri *' ?><input name="full_name" required <?= anon_enabled() ? 'readonly style="background:#f2f2f2"' : '' ?>></label>
    <?php if (anon_enabled()): ?><p class="help" style="margin:0">🔒 Nxënësit ruhen vetëm me kod. Kodi dhe përdoruesi nuk mund të ndryshohen; emrin e vërtetë e mbani vetëm në listën-çelës.</p><?php endif; ?>
    <?= demographics_fields_html() ?>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<dialog id="dlgEnroll"><div class="dlg-body">
  <div class="dlg-head"><h2>Regjistro në klasë</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="id">
    <label>Klasa<select name="class_id" required data-classpick><option value="">— zgjidh —</option><?= admin_class_options() ?></select></label>
    <?= admin_new_class_fields() ?>
    <label class="checkline"><input type="checkbox" name="move" value="1" checked> Hiqe nga klasat e tjera (zhvendosje)</label>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>
<?= import_dialog_html('<label>Klasa e paracaktuar<select name="class_id" data-classpick><option value="0">— sipas kolonës "Kodi i klasës" —</option>' . admin_class_options() . '</select></label>' . admin_new_class_fields(), true) ?>
<script>
document.querySelectorAll('select[data-classpick]').forEach(function (sel) {
  var box = sel.closest('form').querySelector('[data-newclass]');
  function upd() { if (box) box.hidden = !String(sel.value).startsWith('new:'); }
  sel.addEventListener('change', upd); upd();
});
</script>
<?php layout_end();
