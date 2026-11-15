<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');

$classes = qa('SELECT * FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);
$classId = geti('class', $classes ? (int)$classes[0]['id'] : 0);
$class = null;
foreach ($classes as $c) if ((int)$c['id'] === $classId) $class = $c;

if (get('export') === 'last') export_last_import_csv();

if (is_post()) {
    csrf_check();
    $a = post('action');
    if ($a === 'import') {
        $mine = [];
        foreach ($classes as $c) $mine[(int)$c['id']] = $c;
        handle_student_import($mine[posti('class_id')] ?? null, $mine, (int)$u['id']);
        redirect('teacher/students.php?class=' . posti('class_id'));
    }
    $cid = posti('class_id');
    if (!teacher_owns_class((int)$u['id'], $cid)) { flash('err', 'Klasa nuk u gjet.'); redirect('teacher/students.php'); }
    $c = q1('SELECT * FROM classes WHERE id=?', [$cid]);
    $sid = posti('id');
    try {
        if ($a === 'add') {
            $r = create_student(post('full_name'), (int)$c['school_id'], (int)$c['grade'], $cid, (int)$u['id'], post('username') ?: null, post('password') ?: null, post('seq') ?: null, demographics_from_post());
            audit('student.add', $r['code'], $c['name']);
            $_SESSION['import_result'] = ['created' => [$r + ['full_name' => $r['real_name'], 'class' => $c['name'], 'class_code' => $c['class_code']]], 'errors' => []];
            flash('ok', "U shtua {$r['code']} · përdoruesi {$r['username']} · fjalëkalimi: {$r['password']}");
        } elseif ($a === 'bulk') {
            $names = array_filter(array_map('trim', preg_split('/\r?\n/', (string)post('names'))));
            $created = [];
            foreach (array_slice($names, 0, 60) as $name) { $r = create_student($name, (int)$c['school_id'], (int)$c['grade'], $cid, (int)$u['id']); $created[] = $r + ['full_name' => $r['real_name'], 'class' => $c['name'], 'class_code' => $c['class_code']]; }
            $_SESSION['import_result'] = ['created' => $created, 'errors' => []];
            audit('student.import', count($created) . ' nxënës', $c['name']);
            flash('ok', 'U shtuan ' . count($created) . " nxënës. Shkarkoni listën me kredencialet dhe printoni kartelat e hyrjes.");
        } elseif ($a === 'enroll') {
            $s = q1("SELECT * FROM users WHERE username=? AND role='student'", [post('username')]);
            if (!$s) throw new RuntimeException('Nuk u gjet nxënës me përdoruesin "' . post('username') . '".');
            if ((int)$s['school_id'] !== (int)$u['school_id']) throw new RuntimeException('Ky nxënës i përket një shkolle tjetër. Kontaktoni administratorin.');
            q('INSERT IGNORE INTO class_students (class_id, student_id) VALUES (?,?)', [$cid, $s['id']]);
            audit('student.enroll', $s['full_name'], $c['name']);
            flash('ok', $s['full_name'] . ' u regjistrua në klasë.');
        } else {
            // veprimet mbi një nxënës ekzistues të kësaj klase
            if (!qv('SELECT 1 FROM class_students WHERE class_id=? AND student_id=?', [$cid, $sid])) throw new RuntimeException('Nxënësi nuk është në këtë klasë.');
            if ($a === 'remove') {
                q('DELETE FROM class_students WHERE class_id=? AND student_id=?', [$cid, $sid]);
                $others = (int)qv('SELECT COUNT(*) FROM class_students WHERE student_id=?', [$sid]);
                if (post('delete_account') && $others === 0 && (int)qv('SELECT created_by FROM users WHERE id=?', [$sid]) === (int)$u['id']) {
                    q("DELETE FROM users WHERE id=? AND role='student'", [$sid]);
                    audit('student.delete', '#' . $sid);
                    flash('ok', 'Nxënësi u hoq dhe llogaria u fshi.');
                } else {
                    audit('student.unenroll', '#' . $sid, $c['name']);
                    flash('ok', 'Nxënësi u hoq nga klasa.');
                }
            } elseif ($a === 'reset') {
                $r = reset_student_credentials($sid, post('password') ?: null, post('seq') ?: null);
                audit('student.reset', '#' . $sid);
                flash('ok', "Kredencialet u ndryshuan · fjalëkalimi: {$r['password']}");
            } elseif ($a === 'edit') {
                if (anon_enabled()) {
                    // kodi i nxënësit nuk ndryshohet kur anonimizimi është aktiv
                    q("UPDATE users SET active=? WHERE id=? AND role='student'", [post('active') ? 1 : 0, $sid]);
                } else {
                    q("UPDATE users SET full_name=?, active=? WHERE id=? AND role='student'", [post('full_name'), post('active') ? 1 : 0, $sid]);
                }
                save_demographics($sid, demographics_from_post());
                audit('student.edit', (string)qv('SELECT full_name FROM users WHERE id=?', [$sid]));
                flash('ok', 'Të dhënat u ruajtën.');
            }
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('teacher/students.php?class=' . $cid);
}

$rows = $class ? qa('SELECT s.*,
   (SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=s.id) secs,
   (SELECT MAX(last_seen) FROM page_views WHERE student_id=s.id) last_seen,
   (SELECT COUNT(*) FROM quiz_attempts WHERE student_id=s.id) attempts,
   (SELECT COUNT(*) FROM tickets WHERE created_by=s.id AND status<>\'closed\') open_t
  FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? ORDER BY s.full_name', [$classId]) : [];

$picker = function (string $id): string {
    $h = '<div class="pic-picker" data-picpicker="' . $id . '"><div class="seq-dots"><span></span><span></span><span></span></div><div class="pic-grid" style="grid-template-columns:repeat(6,1fr)">';
    foreach (picture_set() as $i => [$emo, $name]) $h .= '<button type="button" class="pic-btn" data-pic="' . $i . '" aria-label="' . e($name) . '"><span aria-hidden="true" style="font-size:1.6rem">' . $emo . '</span></button>';
    return $h . '</div><button type="button" class="btn btn-sm btn-ghost" data-picclear>⌫ Pastro</button><input type="hidden" name="seq" id="' . $id . '"></div>';
};

$actions = $class ? '<a class="btn" href="cards.php?class=' . $classId . '" target="_blank">🖨️ Kartelat e hyrjes</a><button class="btn" data-open="dlgImport">📥 Ngarko listë</button><button class="btn btn-primary" data-open="dlgAdd">＋ Shto nxënës</button>' : '';
layout_start('Nxënësit e mi', ['actions' => $actions]);

if (!$classes): ?>
  <div class="card empty">Krijoni së pari një <a href="classes.php">klasë</a>, pastaj shtoni nxënësit.</div>
<?php layout_end(); exit; endif; ?>

<div class="tabs">
  <?php foreach ($classes as $c): ?><a href="?class=<?= (int)$c['id'] ?>" class="<?= (int)$c['id'] === $classId ? 'active' : '' ?>">🏷️ <?= e($c['name']) ?></a><?php endforeach; ?>
</div>
<?php if ($class): ?>
<?= import_result_html(url('teacher/students.php?export=last&class=' . $classId)) ?>
<div class="row-between mb">
  <?php $girls = count(array_filter($rows, fn($r) => $r['gender'] === 'F')); $boys = count(array_filter($rows, fn($r) => $r['gender'] === 'M')); $sen = count(array_filter($rows, fn($r) => (int)$r['special_needs'] === 1)); ?>
  <div class="muted">Kodi i klasës: <code style="font-size:1.1rem"><?= e($class['class_code']) ?></code> · <?= count($rows) ?> nxënës · 👧 <?= $girls ?> vajza · 👦 <?= $boys ?> djem · ♿ <?= $sen ?> me nevoja të veçanta<?= count($rows) - $girls - $boys > 0 ? ' · <span title="Gjinia e paplotësuar">❔ ' . (count($rows) - $girls - $boys) . '</span>' : '' ?></div>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="class_id" value="<?= $classId ?>">
    <input name="username" placeholder="Përdoruesi i nxënësit ekzistues" style="width:240px" required><button class="btn btn-sm">Regjistro ekzistues</button></form>
</div>

<div class="table-wrap"><table class="t">
  <thead><tr><th>Nxënësi</th><th>Përdoruesi</th><th>Fjalëkalimi</th><?php if (picture_login_enabled()): ?><th>Fotot</th><?php endif; ?><th class="num">Koha</th><th class="num">Tentativa</th><th>Aktiviteti i fundit</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): ?>
    <tr>
      <td><span aria-hidden="true" style="font-size:1.3rem"><?= e($s['avatar']) ?></span> <a href="student.php?id=<?= (int)$s['id'] ?>"><strong><?= e($s['full_name']) ?></strong></a> <?= student_badges($s) ?>
        <?= $s['active'] ? '' : '<span class="badge badge-off">Joaktiv</span>' ?><?= $s['open_t'] ? ' <a href="tickets.php" class="badge badge-open">🎫 ' . (int)$s['open_t'] . '</a>' : '' ?></td>
      <td><code><?= e($s['username']) ?></code></td>
      <td><?= secret_html(dec_hint($s['plain_hint']), 'Shfaq fjalëkalimin') ?></td>
      <?php if (picture_login_enabled()): ?><td><?php $ps = dec_hint($s['picture_hint']); echo $ps ? '<span class="secret"><span hidden>' . picture_seq_html($ps) . '</span><span>••••</span> <button type="button" class="btn btn-ghost btn-sm" onclick="var a=this.parentNode.children;a[0].hidden=!a[0].hidden;a[1].hidden=!a[1].hidden;" aria-label="Shfaq fotot">👁</button></span>' : '—'; ?></td><?php endif; ?>
      <td class="num"><?= e(fmt_duration((int)$s['secs'])) ?></td>
      <td class="num"><?= (int)$s['attempts'] ?></td>
      <td class="small"><?= e(ago($s['last_seen'])) ?></td>
      <td class="actions">
        <a class="btn btn-sm" href="student.php?id=<?= (int)$s['id'] ?>" title="Progresi">📈</a>
        <button class="btn btn-sm" data-open="dlgEdit" data-fill-id="<?= (int)$s['id'] ?>" data-fill-full-name="<?= e($s['full_name']) ?>" data-fill-active="<?= (int)$s['active'] ?>" data-fill-gender="<?= e($s['gender']) ?>" data-fill-mother-tongue="<?= e($s['mother_tongue']) ?>" data-fill-special-needs="<?= $s['special_needs'] === null ? '' : (int)$s['special_needs'] ?>" data-fill-special-needs-type="<?= e($s['special_needs_type']) ?>" data-fill-sen-consent="<?= !empty($s['sen_consent_at']) ? 1 : 0 ?>" data-fill-sen-consent-by="<?= e($s['sen_consent_by'] ?? '') ?>" title="Ndrysho">✏️</button>
        <button class="btn btn-sm" data-open="dlgReset" data-fill-id="<?= (int)$s['id'] ?>" title="Fjalëkalim / foto të reja">🔑</button>
        <button class="btn btn-sm btn-danger" data-open="dlgRemove" data-fill-id="<?= (int)$s['id'] ?>" title="Hiq nga klasa">✕</button>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">🧒 Klasa nuk ka nxënës ende. Përdorni <strong>＋ Shto nxënës</strong> ose <strong>📥 Ngarko listë</strong> (Excel/CSV ose emra të ngjitur).</td></tr><?php endif; ?>
  </tbody>
</table></div>

<dialog id="dlgAdd"><div class="dlg-body">
  <div class="dlg-head"><h2>Shto nxënës në <?= e($class['name']) ?></h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="class_id" value="<?= $classId ?>">
    <label>Emri dhe mbiemri *<input name="full_name" required></label>
    <div class="grid-2"><label>Përdoruesi <span class="help">bosh = automatik</span><input name="username"></label>
      <label>Fjalëkalimi <span class="help">bosh = automatik</span><input name="password"></label></div>
    <?= demographics_fields_html() ?>
    <?php if (picture_login_enabled()): ?><label>3 fotot për hyrjen <span class="help">bosh = rastësore</span></label>
    <?= $picker('seqAdd') ?><?php endif; ?>
    <button class="btn btn-primary">Shto</button>
  </form>
</div></dialog>

<?php $copts = ''; foreach ($classes as $c) $copts .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === $classId ? ' selected' : '') . '>' . e($c['name']) . '</option>';
echo import_dialog_html('<label>Klasa<select name="class_id">' . $copts . '</select></label>', count($classes) > 1); ?>

<dialog id="dlgEdit"><div class="dlg-body">
  <div class="dlg-head"><h2>Ndrysho nxënësin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="id">
    <label><?= anon_enabled() ? 'Kodi i nxënësit' : 'Emri dhe mbiemri' ?><input name="full_name" required <?= anon_enabled() ? 'readonly style="background:#f2f2f2"' : '' ?>></label>
    <?php if (anon_enabled()): ?><p class="help" style="margin:0">🔒 Nxënësit ruhen vetëm me kod. Kodi dhe përdoruesi nuk mund të ndryshohen; emrin e vërtetë e mbani vetëm në listën-çelës.</p><?php endif; ?>
    <?= demographics_fields_html() ?>
    <label class="checkline"><input type="checkbox" name="active" value="1"> Llogaria aktive</label>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>

<dialog id="dlgReset"><div class="dlg-body">
  <div class="dlg-head"><h2>🔑 Fjalëkalim dhe foto të reja</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="id">
    <label>Fjalëkalimi i ri <span class="help">bosh = automatik</span><input name="password"></label>
    <?php if (picture_login_enabled()): ?><label>3 fotot e reja <span class="help">bosh = rastësore</span></label>
    <?= $picker('seqReset') ?><?php endif; ?>
    <button class="btn btn-primary">Ruaj</button>
  </form>
</div></dialog>

<dialog id="dlgRemove"><div class="dlg-body">
  <div class="dlg-head"><h2>Hiq nga klasa</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="id">
    <p>Nxënësi do të hiqet nga klasa <strong><?= e($class['name']) ?></strong>.</p>
    <label class="checkline"><input type="checkbox" name="delete_account" value="1"> Fshi edhe llogarinë (nëse nuk është në klasë tjetër)</label>
    <button class="btn btn-danger">Hiq</button>
  </form>
</div></dialog>
<?php endif; layout_end();
