<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$tid = geti('teacher');

if (is_post()) {
    csrf_check();
    try {
        if (post('action') === 'links') {
            $states = journey_form_states(); $msgs = [];
            foreach (journey_forms() as $k => $F) {
                $v = trim((string)post('link_' . $k));
                if ($v !== '' && !preg_match('~^https?://~i', $v)) throw new RuntimeException('Linku duhet të fillojë me https:// (' . $F['label'] . ')');
                $new = (string)post('state_' . $k);
                if (!isset($states[$new])) $new = '';
                if ($new === 'open' && $v === '') throw new RuntimeException('Vendosni linkun para se ta hapni: ' . $F['label'] . '.');
                $old = journey_form_state($k);
                set_setting('journey_link_' . $k, $v);
                set_setting('journey_state_' . $k, $new);
                set_setting('journey_state_' . $k . '_set', '1');
                if ($new === 'open' && $old !== 'open') {
                    $n = journey_notify_form($k, 'open' . time());
                    $msgs[] = $F['label'] . ' u hap — u njoftuan ' . $n . ' mësues';
                } elseif ($new !== $old) $msgs[] = $F['label'] . ': ' . $states[$new];
            }
            flash('ok', 'Formularët u ruajtën.' . ($msgs ? ' ' . implode(' · ', $msgs) . '.' : ''));
            redirect('admin/journey.php#forms');
        }
        if (post('action') === 'config') {
            $on = (array)($_POST['on'] ?? []); $det = (array)($_POST['detail'] ?? []); $urls = (array)($_POST['url'] ?? []);
            foreach (journey_optional_steps() as $k => $l) journey_set_step_url($k, (string)($urls[$k] ?? ''));
            foreach (journey_optional_steps() as $k => $l) {
                set_setting('journey_off_' . $k, isset($on[$k]) ? '0' : '1');
                set_setting('journey_detail_' . $k, mb_substr(trim((string)($det[$k] ?? '')), 0, 190));
            }
            flash('ok', 'Hapat e rrugëtimit u ruajtën.');
            redirect('admin/journey.php#config');
        }
        if (post('action') === 'remind') {
            $k = (string)post('form');
            if (!isset(journey_forms()[$k])) throw new RuntimeException('Formulari nuk u gjet.');
            $n = journey_notify_form($k, 'r' . time());
            flash('ok', '🔔 Kujtesa u dërgua te ' . $n . ' mësues.');
            redirect('admin/journey.php#forms');
        }
        $tid = posti('teacher');
        if (!qv("SELECT 1 FROM users WHERE id=? AND role='teacher'", [$tid])) throw new RuntimeException('Mësuesi nuk u gjet.');
        journey_handle_post($u, $tid);
    } catch (Throwable $ex) { flash('err', $ex->getMessage()); }
    redirect('admin/journey.php' . ($tid ? '?teacher=' . $tid : '') . (in_array(post('action'), ['plan', 'cycles'], true) || preg_match('/^c\d+_/', (string)post('step')) ? '#p5' : ''));
}

if ($tid) {
    $t = q1("SELECT u.*, s.name school FROM users u LEFT JOIN schools s ON s.id=u.school_id WHERE u.id=? AND u.role='teacher'", [$tid]);
    if (!$t) redirect('admin/journey.php');
    $J = journey_state($tid);
    layout_start('Rrugëtimi · ' . $t['full_name'], ['actions' => '<a class="btn" href="journey.php">↩ Të gjithë mësuesit</a>', 'nav' => 'admin/journey.php']);
    echo journey_css();
    echo '<p class="muted">' . e(($t['school'] ?? '—') . ' · ' . ($t['municipality'] ?? '')) . ' · Shqyrtoni dorëzimet dhe vendosni statusin: <strong>Përfunduar me sukses</strong>, <strong>Përfunduar me komente</strong> ose <strong>Ka nevojë për punë</strong>.</p>';
    echo journey_bar_html($J);
    echo journey_detail_html($J, $tid, 'admin');
    layout_end();
    exit;
}

$fm = get('komuna'); $fs = geti('school');
$teachers = qa("SELECT u.id, u.full_name, u.municipality, s.name school, s.id sid FROM users u LEFT JOIN schools s ON s.id=u.school_id
  WHERE u.role='teacher' AND u.active=1" . ($fm ? ' AND (s.city=? OR u.municipality=?)' : '') . ($fs ? ' AND s.id=' . $fs : '') . ' ORDER BY s.name, u.full_name', $fm ? [$fm, $fm] : []);
$rows = []; $phaseCount = [];
foreach ($teachers as $t) {
    $J = journey_state((int)$t['id']);
    $rows[] = $t + ['J' => $J];
    $k = $J['current'] === null ? 'done' : $J['phases'][$J['current']]['label'] . ' — ' . $J['phases'][$J['current']]['short'];
    $phaseCount[$k] = ($phaseCount[$k] ?? 0) + 1;
}
$pending = array_sum(array_map(fn($r) => $r['J']['pending_review'], $rows));
layout_start('Rrugëtimi i mësuesve');
echo journey_css();
?>
<div class="grid-3">
  <div class="stat"><div class="lbl">Mësues në pilotim</div><div class="val"><?= count($rows) ?></div></div>
  <div class="stat"><div class="lbl">⏳ Dorëzime për shqyrtim</div><div class="val"><?= $pending ?></div></div>
  <div class="stat"><div class="lbl">Progresi mesatar</div><div class="val"><?= $rows ? round(array_sum(array_map(fn($r) => $r['J']['pct'], $rows)) / count($rows)) : 0 ?>%</div></div>
</div>
<section class="card"><h2>📍 Ku ndodhen mësuesit</h2>
  <?php foreach (journey_phases() as $ph): $k = $ph['label'] . ' — ' . $ph['short']; ?>
    <span class="badge" style="margin:2px"><?= $ph['icon'] ?> <?= e($k) ?>: <strong><?= (int)($phaseCount[$k] ?? 0) ?></strong></span>
  <?php endforeach; ?><span class="badge" style="margin:2px">🎉 Përfunduar: <strong><?= (int)($phaseCount['done'] ?? 0) ?></strong></span>
</section>
<form method="get" class="row mb"><select name="komuna" onchange="this.form.submit()"><option value="">Të gjitha komunat</option><?= implode('', array_map(fn($m) => '<option' . ($fm === $m ? ' selected' : '') . '>' . e($m) . '</option>', municipalities())) ?></select></form>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Mësuesi</th><th>Shkolla</th><th>Progresi</th><th>Faza aktuale</th><th class="num">⏳ Për shqyrtim</th><th class="num">⚠️ Punë</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): $J = $r['J']; ?>
    <tr><td><strong><?= e($r['full_name']) ?></strong><div class="small muted"><?= e($r['municipality'] ?? '') ?></div></td><td class="small"><?= e($r['school'] ?? '—') ?></td>
      <td><div class="jr-mini"><div style="width:<?= $J['pct'] ?>%"></div></div><span class="small"><?= $J['pct'] ?>% · <?= $J['done'] ?>/<?= $J['total'] ?></span></td>
      <td class="small"><?= $J['current'] === null ? '🎉 Përfunduar' : $J['phases'][$J['current']]['icon'] . ' ' . e($J['phases'][$J['current']]['label'] . ' — ' . $J['phases'][$J['current']]['short']) ?></td>
      <td class="num"><?= $J['pending_review'] ? '<strong>' . $J['pending_review'] . '</strong>' : 0 ?></td><td class="num"><?= $J['needs_work'] ?: 0 ?></td>
      <td class="actions"><a class="btn btn-sm btn-primary" href="?teacher=<?= (int)$r['id'] ?>">Hape →</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">Nuk ka mësues.</td></tr><?php endif; ?></tbody>
</table></div>
<section class="card mt" id="config"><h2>⚙️ Hapat e rrugëtimit</h2>
  <p class="small muted">Zgjidhni cilat hapa përfshihen në rrugëtimin e mësuesve. Hapat pa ✓ nuk shfaqen dhe nuk llogariten në progres. Te «Detajet» mund të shkruani p.sh. datat e trajnimit; te «Linku» vendosni ose ndryshoni URL-në e pyetësorit të secilit proces — mësuesit i shohin te hapi.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="config">
  <div class="table-wrap"><table class="t"><thead><tr><th style="width:60px">Përfshirë</th><th>Hapi</th><th>Detajet (opsionale)</th><th>🔗 Linku i pyetësorit (URL)</th></tr></thead><tbody>
  <?php foreach (journey_optional_steps() as $k => $l): ?>
    <tr><td><input type="checkbox" name="on[<?= e($k) ?>]" value="1" <?= journey_step_enabled($k) ? 'checked' : '' ?>></td><td><?= e($l) ?></td>
      <td><input name="detail[<?= e($k) ?>]" value="<?= e(setting('journey_detail_' . $k)) ?>" placeholder="<?= $k === 'training' ? 'p.sh. 12 dhe 13 Nëntor 2026' : '' ?>" style="min-width:200px"></td>
      <td><input name="url[<?= e($k) ?>]" value="<?= e(journey_step_url($k)) ?>" placeholder="https://forms.gle/…" style="min-width:230px"></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <button class="btn btn-primary mt">💾 Ruaj hapat</button></form>
</section>
<section class="card mt" id="forms"><h2>🔗 Formularët (Google Forms) dhe njoftimet</h2>
  <p class="small muted">Kur e vendosni një formular <strong>🟢 Hapur</strong>, mësuesit që ende s'e kanë plotësuar marrin njoftim te 🔔 dhe kujtesë çdo javë. Linku i ditarit shfaqet brenda secilit cikël (📓 Ditari i ciklit). Kur e vendosni <strong>✅ Përfunduar</strong>, linku fshihet dhe nuk plotësohet më.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="links">
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Formulari</th><th>Linku</th><th>Gjendja</th><th class="num">Plotësuar</th></tr></thead><tbody>
    <?php foreach (journey_forms() as $k => $F): $fs = journey_form_state($k);
        $filled = $F['step'] ? (int)qv("SELECT COUNT(*) FROM journey_status js JOIN users u ON u.id=js.teacher_id AND u.active=1 WHERE js.step_key=? AND js.status IN ('submitted','done','done_comments')", [$F['step']]) : null; ?>
      <tr><td><strong><?= $k === 'diary' ? '📓' : '📋' ?> <?= e($F['label']) ?></strong><div class="small muted"><?= e($F['proc']) ?></div></td>
        <td><input name="link_<?= $k ?>" value="<?= e(journey_form_link($k)) ?>" placeholder="https://forms.gle/…" style="min-width:260px"></td>
        <td><select name="state_<?= $k ?>"><?php foreach (journey_form_states() as $sv => $sl): ?><option value="<?= $sv ?>" <?= $fs === $sv ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></td>
        <td class="num"><?= $filled === null ? '—' : $filled . ' / ' . count($rows) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <button class="btn btn-primary mt">💾 Ruaj formularët</button></form>
  <div class="row mt"><?php foreach (journey_forms() as $k => $F) if (journey_form_state($k) === 'open'): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="remind"><input type="hidden" name="form" value="<?= $k ?>"><button class="btn btn-sm">🔔 Dërgo kujtesë tani: <?= e($F['label']) ?></button></form>
  <?php endif; ?></div>
</section>
<?php layout_end();
