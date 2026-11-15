<?php
// Teacher training sessions & attendance (English UI — used for the stakeholder report)
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

const T_STATUSES = ['registered' => 'Registered', 'attended' => 'Attended', 'completed' => 'Completed', 'absent' => 'Absent'];
const T_MODES = ['in_person' => 'In person', 'online' => 'Online', 'blended' => 'Blended'];
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;

if (is_post()) {
    csrf_check();
    $a = post('action');
    $id = posti('id');
    try {
        if ($a === 'save') {
            if (trim((string)post('title')) === '' || !$valid(post('start_date'))) throw new RuntimeException('Title and start date are required.');
            $data = [mb_substr(post('title'), 0, 200), post('topic') ?: null, post('trainer') ?: null, post('location') ?: null,
                valid_municipality(post('municipality')) ? post('municipality') : null, isset(T_MODES[post('mode')]) ? post('mode') : 'in_person',
                post('start_date'), $valid(post('end_date')), max(0, min(500, (float)str_replace(',', '.', (string)post('hours')))), post('notes') ?: null];
            if ($id) {
                q('UPDATE trainings SET title=?, topic=?, trainer=?, location=?, municipality=?, mode=?, start_date=?, end_date=?, hours=?, notes=? WHERE id=?', [...$data, $id]);
            } else {
                q('INSERT INTO trainings (title, topic, trainer, location, municipality, mode, start_date, end_date, hours, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [...$data, $u['id']]);
                $id = (int)db()->lastInsertId();
            }
            audit('settings', 'Training: ' . $data[0], 'saved');
            flash('ok', 'Training session saved.');
            redirect('admin/trainings.php?id=' . $id);
        } elseif ($a === 'delete') {
            q('DELETE FROM trainings WHERE id=?', [$id]);
            audit('settings', 'Training #' . $id, 'deleted');
            flash('ok', 'Training session deleted.');
        } elseif ($a === 'add_teachers') {
            $n = 0;
            foreach ((array)($_POST['teachers'] ?? []) as $tid) {
                if (qv("SELECT 1 FROM users WHERE id=? AND role='teacher'", [(int)$tid])) {
                    $n += q('INSERT IGNORE INTO training_attendance (training_id, teacher_id, status) VALUES (?,?,?)', [$id, (int)$tid, post('status') && isset(T_STATUSES[post('status')]) ? post('status') : 'registered'])->rowCount();
                }
            }
            flash('ok', "$n teacher(s) added to the session.");
            redirect('admin/trainings.php?id=' . $id);
        } elseif ($a === 'attendance') {
            foreach ((array)($_POST['att'] ?? []) as $tid => $row) {
                $sc = fn($v) => ($v === '' || $v === null) ? null : max(0, min(100, (int)$v));
                q('UPDATE training_attendance SET status=?, pre_score=?, post_score=?, satisfaction=?, certificate=?, notes=?, updated_at=NOW() WHERE training_id=? AND teacher_id=?', [
                    isset(T_STATUSES[$row['status'] ?? '']) ? $row['status'] : 'registered', $sc($row['pre'] ?? ''), $sc($row['post'] ?? ''),
                    ($row['sat'] ?? '') === '' ? null : max(1, min(5, (int)$row['sat'])), !empty($row['cert']) ? 1 : 0, mb_substr(trim((string)($row['notes'] ?? '')), 0, 255) ?: null, $id, (int)$tid,
                ]);
            }
            audit('settings', 'Training #' . $id, 'attendance updated');
            flash('ok', 'Attendance saved.');
            redirect('admin/trainings.php?id=' . $id);
        } elseif ($a === 'cert_settings') {
            foreach (array_keys(tcert_defaults()) as $k) if (!in_array($k, ['tcert_bilingual', 'tcert_merge_all'], true)) set_setting($k, mb_substr(trim((string)post($k)), 0, 300));
            set_setting('tcert_bilingual', post('tcert_bilingual') ? '1' : '0');
            set_setting('tcert_merge_all', post('tcert_merge_all') ? '1' : '0');
            for ($i = 1; $i <= 3; $i++) {
                if (post('dellogo' . $i)) tcert_delete_logo($i);
                if (isset($_FILES['logo' . $i])) tcert_save_logo($i, $_FILES['logo' . $i]);
            }
            audit('settings', 'Certifikatat e mësuesve', 'pamja u ndryshua');
            flash('ok', 'Pamja e certifikatave u ruajt.');
            redirect('admin/trainings.php' . ($id ? '?id=' . $id : '') . '#cert');
        } elseif ($a === 'material_upload') {
            $files = $_FILES['files'] ?? null; $n = 0; $errs = [];
            if ($files && is_array($files['name'])) {
                foreach ($files['name'] as $i => $nm) {
                    $f = ['name' => $nm, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                    if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
                    try { tmat_store($id, $f, count($files['name']) === 1 ? trim((string)post('title')) : '', (bool)post('all_teachers'), (int)$u['id']); $n++; }
                    catch (Throwable $ex) { $errs[] = $ex->getMessage(); }
                }
            }
            if ($n) audit('settings', 'Training #' . $id, "$n material(e) u ngarkuan");
            if ($errs) flash('err', implode(' · ', $errs));
            if ($n || !$errs) flash('ok', $n ? "📚 U ngarkuan $n material(e)." : 'Zgjidhni të paktën një skedar.');
            redirect('admin/trainings.php?id=' . $id . '#materials');
        } elseif ($a === 'material_delete') {
            $m = q1('SELECT * FROM training_materials WHERE id=? AND training_id=?', [posti('mid'), $id]);
            if ($m) { @unlink(tmat_dir() . '/' . basename($m['stored_name'])); q('DELETE FROM training_materials WHERE id=?', [$m['id']]); flash('ok', 'Materiali u fshi.'); }
            redirect('admin/trainings.php?id=' . $id . '#materials');
        } elseif ($a === 'material_toggle') {
            q('UPDATE training_materials SET all_teachers = 1 - all_teachers WHERE id=? AND training_id=?', [posti('mid'), $id]);
            redirect('admin/trainings.php?id=' . $id . '#materials');
        } elseif ($a === 'remove_teacher') {
            q('DELETE FROM training_attendance WHERE training_id=? AND teacher_id=?', [$id, posti('teacher_id')]);
            flash('ok', 'Teacher removed from the session.');
            redirect('admin/trainings.php?id=' . $id);
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/trainings.php');
}

$form = function (array $t = []) {
    ob_start(); ?>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
      <label>Title *<input name="title" required maxlength="200" value="<?= e($t['title'] ?? '') ?>" placeholder="e.g. Teaching with ADT Studio digital textbooks — Module 1"></label>
      <div class="grid-2"><label>Topic<input name="topic" value="<?= e($t['topic'] ?? '') ?>" placeholder="Platform use, accessibility, classroom practice"></label>
        <label>Trainer(s)<input name="trainer" value="<?= e($t['trainer'] ?? '') ?>"></label></div>
      <div class="grid-2"><label>Municipality<select name="municipality"><?= municipality_options($t['municipality'] ?? null, true, '— select —') ?></select></label>
        <label>Venue<input name="location" value="<?= e($t['location'] ?? '') ?>"></label></div>
      <div class="grid-2"><label>Start date *<input type="date" name="start_date" required value="<?= e($t['start_date'] ?? date('Y-m-d')) ?>"></label>
        <label>End date<input type="date" name="end_date" value="<?= e($t['end_date'] ?? '') ?>"></label></div>
      <div class="grid-2"><label>Duration (hours)<input name="hours" inputmode="decimal" value="<?= e($t['hours'] ?? '') ?>" placeholder="e.g. 8"></label>
        <label>Delivery mode<select name="mode"><?php foreach (T_MODES as $k => $l): ?><option value="<?= $k ?>" <?= ($t['mode'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div>
      <label>Notes<textarea name="notes" rows="3"><?= e($t['notes'] ?? '') ?></textarea></label>
      <button class="btn btn-primary">Save session</button>
    </form>
    <?php return (string)ob_get_clean();
};

/* ---------- Session detail ---------- */
if ($id = geti('id')) {
    $t = q1('SELECT * FROM trainings WHERE id=?', [$id]);
    if (!$t) { flash('err', 'Session not found.'); redirect('admin/trainings.php'); }
    $att = qa("SELECT a.*, u.full_name, u.municipality, u.last_login, s.name school,
        (SELECT COUNT(*) FROM classes c WHERE c.teacher_id=u.id) classes,
        (SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=u.id) students
        FROM training_attendance a JOIN users u ON u.id=a.teacher_id LEFT JOIN schools s ON s.id=u.school_id WHERE a.training_id=? ORDER BY u.full_name", [$id]);
    $notIn = qa("SELECT u.id, u.full_name, u.municipality, s.name school FROM users u LEFT JOIN schools s ON s.id=u.school_id
        WHERE u.role='teacher' AND u.active=1 AND u.id NOT IN (SELECT teacher_id FROM training_attendance WHERE training_id=?) ORDER BY u.municipality, u.full_name", [$id]);
    $cnt = array_count_values(array_column($att, 'status'));
    layout_start('🎓 ' . $t['title'], ['nav' => 'admin/trainings.php', 'actions' => '<a class="btn" href="trainings.php">← All sessions</a><button class="btn" data-open="dlgEdit">✏️ Edit</button><a class="btn" target="_blank" href="../teacher_certificate.php?training=' . $id . '">🎓 Certifikatat</a><button class="btn" data-open="dlgCert">🎨 Pamja e certifikatës</button><button class="btn btn-primary" data-open="dlgAdd">＋ Add teachers</button>']);
    ?>
    <div class="stats">
      <div class="stat"><div class="lbl">📅 Dates</div><div class="val" style="font-size:1.1rem"><?= e(date('d.m.Y', strtotime($t['start_date']))) ?><?= $t['end_date'] && $t['end_date'] !== $t['start_date'] ? ' – ' . e(date('d.m.Y', strtotime($t['end_date']))) : '' ?></div><div class="sub"><?= e(T_MODES[$t['mode']] ?? '') ?> · <?= e($t['municipality'] ?: '—') ?></div></div>
      <div class="stat"><div class="lbl">⏱️ Duration</div><div class="val"><?= e(rtrim(rtrim(number_format((float)$t['hours'], 1, '.', ''), '0'), '.')) ?> h</div></div>
      <div class="stat"><div class="lbl">👩‍🏫 Participants</div><div class="val"><?= count($att) ?></div><div class="sub"><?= ($cnt['attended'] ?? 0) + ($cnt['completed'] ?? 0) ?> attended · <?= $cnt['completed'] ?? 0 ?> completed · <?= $cnt['absent'] ?? 0 ?> absent</div></div>
      <div class="stat"><div class="lbl">📈 Avg. score gain</div><?php $pairs = array_filter($att, fn($r) => $r['pre_score'] !== null && $r['post_score'] !== null); ?>
        <div class="val"><?= $pairs ? '+' . round(array_sum(array_map(fn($r) => $r['post_score'] - $r['pre_score'], $pairs)) / count($pairs)) . ' pts' : '—' ?></div><div class="sub">pre → post test</div></div>
    </div>
    <?php if ($t['topic'] || $t['trainer'] || $t['notes']): ?><div class="card small"><?= $t['topic'] ? '<strong>Topic:</strong> ' . e($t['topic']) . '<br>' : '' ?><?= $t['trainer'] ? '<strong>Trainer:</strong> ' . e($t['trainer']) . '<br>' : '' ?><?= $t['location'] ? '<strong>Venue:</strong> ' . e($t['location']) . '<br>' : '' ?><?= nl2br(e($t['notes'] ?? '')) ?></div><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="attendance"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="table-wrap"><table class="t">
      <thead><tr><th>Teacher</th><th>Status</th><th class="num">Pre-test %</th><th class="num">Post-test %</th><th class="num">Satisfaction (1–5)</th><th>Certificate</th><th>Notes</th><th>On the platform</th><th></th></tr></thead>
      <tbody><?php foreach ($att as $r): $k = 'att[' . (int)$r['teacher_id'] . ']'; ?>
        <tr><td><strong><?= e($r['full_name']) ?></strong><div class="small muted"><?= e($r['school'] ?? '') ?> · <?= e($r['municipality'] ?? '—') ?></div></td>
          <td><select name="<?= $k ?>[status]" style="width:auto"><?php foreach (T_STATUSES as $sk => $sl): ?><option value="<?= $sk ?>" <?= $r['status'] === $sk ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></td>
          <td><input name="<?= $k ?>[pre]" type="number" min="0" max="100" value="<?= e($r['pre_score']) ?>" style="width:80px"></td>
          <td><input name="<?= $k ?>[post]" type="number" min="0" max="100" value="<?= e($r['post_score']) ?>" style="width:80px"></td>
          <td><input name="<?= $k ?>[sat]" type="number" min="1" max="5" value="<?= e($r['satisfaction']) ?>" style="width:70px"></td>
          <td><label class="checkline"><input type="checkbox" name="<?= $k ?>[cert]" value="1" <?= $r['certificate'] ? 'checked' : '' ?>> issued</label>
            <?php if (in_array($r['status'], TCERT_STATUSES, true)): ?><a class="btn btn-sm" target="_blank" href="../teacher_certificate.php?training=<?= $id ?>&teacher=<?= (int)$r['teacher_id'] ?>" title="Certifikata">🎓</a><?php endif; ?></td>
          <td><input name="<?= $k ?>[notes]" value="<?= e($r['notes']) ?>" style="min-width:140px"></td>
          <td class="small"><?= (int)$r['classes'] ?> class(es) · <?= (int)$r['students'] ?> students<br><span class="muted">last login <?= e($r['last_login'] ? fmt_dt($r['last_login'], false) : 'never') ?></span></td>
          <td class="actions"><button class="btn btn-sm btn-danger" form="rm<?= (int)$r['teacher_id'] ?>" title="Remove">✕</button></td></tr>
      <?php endforeach; if (!$att): ?><tr><td colspan="9" class="empty">No teachers yet — use “＋ Add teachers”.</td></tr><?php endif; ?></tbody>
    </table></div>
    <?php if ($att): ?><p class="mt"><button class="btn btn-primary">💾 Save attendance</button></p><?php endif; ?>
    </form>
    <?php foreach ($att as $r): ?><form id="rm<?= (int)$r['teacher_id'] ?>" method="post" data-confirm="Remove this teacher from the session?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_teacher"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="teacher_id" value="<?= (int)$r['teacher_id'] ?>"></form><?php endforeach; ?>
    <?php $mats = qa('SELECT * FROM training_materials WHERE training_id=? ORDER BY id', [$id]); ?>
    <section class="card mt" id="materials"><h2>📚 Materialet e trajnimit</h2>
      <p class="small muted">Prezantimet, udhëzuesit, instrumentet etj. (<?= e(implode(', ', TMAT_EXT)) ?> · deri <?= TMAT_MAX_MB ?> MB). Mësuesit e regjistruar në këtë trajnim i shkarkojnë te <b>🧭 Rrugëtimi im → Trajnimi</b>.</p>
      <?php if ($mats): ?><div class="table-wrap"><table class="t"><thead><tr><th>Materiali</th><th>Madhësia</th><th>Kush e sheh</th><th>Ngarkuar</th><th></th></tr></thead><tbody>
        <?php foreach ($mats as $m): ?>
          <tr><td><?= tmat_icon($m['original_name']) ?> <a href="../training_material.php?id=<?= (int)$m['id'] ?>" target="_blank"><?= e($m['title'] ?: $m['original_name']) ?></a><?= $m['title'] ? '<div class="small muted">' . e($m['original_name']) . '</div>' : '' ?></td>
            <td class="small"><?= tmat_size((int)$m['size']) ?></td>
            <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="material_toggle"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-sm btn-ghost" title="Ndrysho"><?= $m['all_teachers'] ? '🌍 Të gjithë mësuesit' : '👩‍🏫 Pjesëmarrësit' ?></button></form></td>
            <td class="small muted"><?= e(fmt_dt($m['uploaded_at'])) ?></td>
            <td class="actions"><form method="post" class="inline" data-confirm="Ta fshij materialin?"><?= csrf_field() ?><input type="hidden" name="action" value="material_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-danger">🗑️</button></form></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="form mt" style="max-width:640px"><?= csrf_field() ?><input type="hidden" name="action" value="material_upload"><input type="hidden" name="id" value="<?= $id ?>">
        <label>Skedarët (mund të zgjidhni disa njëherësh)<input type="file" name="files[]" multiple required accept=".<?= implode(',.', TMAT_EXT) ?>"></label>
        <label>Titulli (opsional, kur ngarkoni një skedar)<input name="title" placeholder="p.sh. Dita 1 – Prezantimi SAMR"></label>
        <label class="checkline"><input type="checkbox" name="all_teachers" value="1"> 🌍 I dukshëm për të gjithë mësuesit (jo vetëm për pjesëmarrësit)</label>
        <button class="btn btn-primary">⬆️ Ngarko materialet</button>
      </form>
    </section>
    <form method="post" class="mt" data-confirm="Delete this training session and its attendance records?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-sm btn-danger">🗑️ Delete session</button></form>

    <dialog id="dlgCert"><div class="dlg-body"><div class="dlg-head"><h2>🎨 Pamja e certifikatës</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Close">✕</button></div>
      <p class="small muted">Titulli, tema, datat, vendi dhe orët merren automatikisht nga ky trajnim. Këtu ndryshoni tekstet e përbashkëta, nënshkruesit dhe logot (vlejnë për të gjitha trajnimet). Certifikata jepet për mësuesit me status <b>Attended</b> ose <b>Completed</b>.</p>
      <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cert_settings"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid-2" style="gap:8px">
          <label>Titulli<input name="tcert_heading" value="<?= e(tcert_setting('tcert_heading')) ?>"></label>
          <label>Nëntitulli<input name="tcert_subheading" value="<?= e(tcert_setting('tcert_subheading')) ?>"></label>
          <label>Titulli në anglisht<input name="tcert_heading_en" value="<?= e(tcert_setting('tcert_heading_en')) ?>"></label>
          <label>Ngjyra<input type="color" name="tcert_color" value="<?= e(tcert_setting('tcert_color')) ?>"></label>
          <label>«I jepet»<input name="tcert_awarded" value="<?= e(tcert_setting('tcert_awarded')) ?>"></label>
          <label>«Për pjesëmarrjen në»<input name="tcert_for" value="<?= e(tcert_setting('tcert_for')) ?>"></label>
        </div>
        <label>Projekti / programi<input name="tcert_program" value="<?= e(tcert_setting('tcert_program')) ?>"></label>
        <label>Projekti në anglisht<input name="tcert_program_en" value="<?= e(tcert_setting('tcert_program_en')) ?>"></label>
        <div class="grid-2" style="gap:8px">
          <label>Nënshkruesi 1 (bosh = trajnuesi i sesionit)<input name="tcert_sign1_name" value="<?= e(setting('tcert_sign1_name')) ?>" placeholder="<?= e($t['trainer'] ?? '') ?>"></label>
          <label>Roli 1<input name="tcert_sign1_role" value="<?= e(tcert_setting('tcert_sign1_role')) ?>"></label>
          <label>Nënshkruesi 2<input name="tcert_sign2_name" value="<?= e(setting('tcert_sign2_name')) ?>"></label>
          <label>Roli 2<input name="tcert_sign2_role" value="<?= e(tcert_setting('tcert_sign2_role')) ?>"></label>
        </div>
        <label class="checkline"><input type="checkbox" name="tcert_bilingual" value="1" <?= tcert_setting('tcert_bilingual') === '1' ? 'checked' : '' ?>> Shto rreshtat në anglisht</label>
        <label class="checkline"><input type="checkbox" name="tcert_merge_all" value="1" <?= tcert_merge_all() ? 'checked' : '' ?>> <b>Një certifikatë për mësues</b> — bashko të gjitha sesionet ku ka marrë pjesë (p.sh. Dita 1 + Dita 2 → 12–13 Nëntor)</label>
        <label>Titulli kur sesionet kanë tituj të ndryshëm<input name="tcert_combined_title" value="<?= e(tcert_setting('tcert_combined_title')) ?>"></label>
        <?php $lg = tcert_logos(); for ($i = 1; $i <= 3; $i++): ?>
          <div class="row" style="gap:8px"><strong>Logo <?= $i ?></strong><?= isset($lg[$i]) ? '<img src="' . e($lg[$i]) . '" alt="" style="height:36px"> <label class="checkline small"><input type="checkbox" name="dellogo' . $i . '" value="1"> hiqe</label>' : '<span class="small muted">—</span>' ?>
            <input type="file" name="logo<?= $i ?>" accept=".png,.jpg,.jpeg,.svg,.webp" style="flex:1"></div>
        <?php endfor; ?>
        <p class="small muted" style="margin:0">Ngarkoni logot zyrtare të partnerëve që keni leje t'i përdorni (PNG/SVG, deri 1 MB).</p>
        <button class="btn btn-primary">💾 Ruaj</button>
      </form></div></dialog>
    <dialog id="dlgEdit"><div class="dlg-body"><div class="dlg-head"><h2>Edit session</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Close">✕</button></div><?= $form($t) ?></div></dialog>
    <dialog id="dlgAdd"><div class="dlg-body"><div class="dlg-head"><h2>Add teachers</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Close">✕</button></div>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="add_teachers"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="search" placeholder="🔎 Filter…" oninput="var v=this.value.toLowerCase();this.form.querySelectorAll('.tpick').forEach(function(l){l.hidden=v&&!l.textContent.toLowerCase().includes(v)})">
        <label class="checkline"><input type="checkbox" onclick="var c=this.checked;this.form.querySelectorAll('.tpick:not([hidden]) input').forEach(function(i){i.checked=c})"> <strong>Select all (visible)</strong></label>
        <div style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:8px">
          <?php foreach ($notIn as $tt): ?><label class="checkline tpick"><input type="checkbox" name="teachers[]" value="<?= (int)$tt['id'] ?>"> <?= e($tt['full_name']) ?> <span class="small muted">· <?= e($tt['school'] ?? '') ?> · <?= e($tt['municipality'] ?? '—') ?></span></label><?php endforeach; ?>
          <?php if (!$notIn): ?><p class="muted">All teachers are already in this session.</p><?php endif; ?>
        </div>
        <label>Initial status<select name="status"><?php foreach (T_STATUSES as $sk => $sl): ?><option value="<?= $sk ?>" <?= $sk === 'attended' ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary">Add selected</button>
      </form></div></dialog>
    <?php
    layout_end();
    exit;
}

/* ---------- Sessions list ---------- */
$rows = qa("SELECT t.*, COUNT(a.teacher_id) participants, SUM(a.status IN ('attended','completed')) attended, SUM(a.status='completed') completed,
    AVG(a.post_score - a.pre_score) gain FROM trainings t LEFT JOIN training_attendance a ON a.training_id=t.id GROUP BY t.id ORDER BY t.start_date DESC");
$trained = (int)qv("SELECT COUNT(DISTINCT teacher_id) FROM training_attendance WHERE status IN ('attended','completed')");
$teachers = (int)qv("SELECT COUNT(*) FROM users WHERE role='teacher' AND active=1");
layout_start('Teacher trainings', ['actions' => '<a class="btn" href="training_report.php">📄 Stakeholder report</a><button class="btn btn-primary" data-open="dlgNew">＋ New session</button>']);
?>
<p class="muted">Record each training session and which teachers attended. The <a href="training_report.php">stakeholder report</a> links training to how teachers then use the platform and how their students progress with the ADT Studio books.</p>
<div class="stats">
  <div class="stat"><div class="lbl">🎓 Sessions</div><div class="val"><?= count($rows) ?></div><div class="sub"><?= e(rtrim(rtrim(number_format(array_sum(array_column($rows, 'hours')), 1, '.', ''), '0'), '.')) ?> training hours</div></div>
  <div class="stat"><div class="lbl">👩‍🏫 Teachers trained</div><div class="val"><?= $trained ?>/<?= $teachers ?></div><?= progress_bar(pct($trained, $teachers)) ?></div>
</div>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Session</th><th>Date</th><th>Municipality</th><th>Mode</th><th class="num">Hours</th><th class="num">Participants</th><th class="num">Attended</th><th class="num">Completed</th><th class="num">Avg. gain</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $t): ?>
    <tr><td><a href="?id=<?= (int)$t['id'] ?>"><strong><?= e($t['title']) ?></strong></a><div class="small muted"><?= e($t['trainer'] ?? '') ?></div></td>
      <td class="small"><?= e(date('d.m.Y', strtotime($t['start_date']))) ?></td><td><?= e($t['municipality'] ?? '—') ?></td><td class="small"><?= e(T_MODES[$t['mode']] ?? '') ?></td>
      <td class="num"><?= e(rtrim(rtrim(number_format((float)$t['hours'], 1, '.', ''), '0'), '.')) ?></td><td class="num"><?= (int)$t['participants'] ?></td><td class="num"><?= (int)$t['attended'] ?></td><td class="num"><?= (int)$t['completed'] ?></td>
      <td class="num"><?= $t['gain'] !== null ? '+' . round((float)$t['gain']) : '—' ?></td><td class="actions"><a class="btn btn-sm" href="?id=<?= (int)$t['id'] ?>">Open →</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="10" class="empty">🎓 No training sessions yet. Create the first one.</td></tr><?php endif; ?></tbody>
</table></div>
<dialog id="dlgNew"><div class="dlg-body"><div class="dlg-head"><h2>New training session</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Close">✕</button></div><?= $form() ?></div></dialog>
<?php layout_end();
