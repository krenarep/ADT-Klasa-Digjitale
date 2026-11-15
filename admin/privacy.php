<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$tab = in_array(get('tab'), ['audit', 'backup', 'retention', 'consent', 'anon'], true) ? get('tab') : 'audit';

/* ---------- Veprimet ---------- */
if (get('download')) {
    $p = backup_path(get('download'));
    if (!$p) { http_response_code(404); exit('Nuk u gjet.'); }
    audit('backup.download', basename($p));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    header('Content-Length: ' . filesize($p));
    while (ob_get_level()) ob_end_flush();   // pa buffer: skedari dërgohet drejtpërdrejt
    readfile($p);
    exit;
}
if (is_post()) {
    csrf_check();
    $a = post('action');
    try {
        if ($a === 'backup') {
            $n = backup_create('manual');
            flash('ok', 'Kopja rezervë u krijua: ' . $n);
        } elseif ($a === 'backup_delete' && ($p = backup_path(post('name')))) {
            unlink($p); audit('backup', post('name'), 'u fshi'); flash('ok', 'Kopja u fshi.');
        } elseif ($a === 'retention') {
            foreach (['retention_events_days' => [30, 3650], 'retention_audit_days' => [90, 3650], 'backups_keep' => [1, 90]] as $k => [$min, $max]) {
                set_setting($k, (string)max($min, min($max, posti($k))));
            }
            set_setting('backup_auto', post('backup_auto') ? '1' : '0');
            audit('settings', 'Ruajtja e të dhënave', 'klikime ' . posti('retention_events_days') . 'd · auditim ' . posti('retention_audit_days') . 'd · kopje ' . posti('backups_keep'));
            flash('ok', 'Cilësimet u ruajtën.');
        } elseif ($a === 'cleanup') {
            flash('ok', 'Fshirja u krye: ' . run_retention_cleanup() . '.');
        } elseif ($a === 'anon_settings') {
            set_setting('anonymize_students', post('anonymize_students') ? '1' : '0');
            foreach ((array)($_POST['code'] ?? []) as $sid => $code) {
                $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
                $code = substr($code, 0, 3);
                q('UPDATE schools SET code=? WHERE id=?', [$code !== '' ? $code : null, (int)$sid]);
            }
            audit('settings', 'Anonimizimi', post('anonymize_students') ? 'aktiv' : 'joaktiv');
            flash('ok', 'Cilësimet e anonimizimit u ruajtën.');
        } elseif ($a === 'purge_backups') {
            $n = 0;
            foreach (backups_list() as $b) if (($p = backup_path($b['name'])) && @unlink($p)) $n++;
            $new = backup_create('pas-anonimizimit');
            audit('backup', 'Fshirje e kopjeve para anonimizimit', "$n u fshinë · e re: $new");
            flash('ok', "$n kopje rezervë të vjetra (me emra) u fshinë. U krijua kopje e re pa emra: $new");
        } elseif ($a === 'anon_run') {
            $ids = array_map('intval', array_column(qa(posti('school') ? 'SELECT id FROM classes WHERE school_id=' . posti('school') . ' ORDER BY grade, name' : 'SELECT id FROM classes ORDER BY school_id, grade, name'), 'id'));
            $key = anonymize_classes($ids);
            if ($key) anon_send_key_csv($key, 'lista_celes_nxenesit_' . date('Ymd_His') . '.csv');
            flash('ok', 'Nuk ka nxënës me emër për t\'u anonimizuar.');
        } elseif ($a === 'purge_sen') {
            $sid = posti('id');
            q("UPDATE users SET special_needs=NULL, special_needs_type=NULL, sen_consent_at=NULL, sen_consent_by=NULL WHERE id=? AND role='student'", [$sid]);
            audit('consent.withdraw', '#' . $sid, 'Fshirje nga administratori');
            flash('ok', 'Të dhënat e nevojave të veçanta për këtë nxënës u fshinë.');
        }
    } catch (Throwable $ex) {
        flash('err', $ex->getMessage());
    }
    redirect('admin/privacy.php?tab=' . $tab);
}

/* ---------- Auditimi: filtrat dhe eksporti ---------- */
$af = get('a');
$who = get('who');
$days = in_array(geti('days', 30), [1, 7, 30, 90, 365], true) ? geti('days', 30) : 30;
$w = ['created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'];
$p = [];
if ($af !== '' && isset(audit_actions()[$af])) { $w[] = 'action = ?'; $p[] = $af; }
if ($who !== '') { $w[] = '(user_name LIKE ? OR target LIKE ?)'; $p[] = '%' . $who . '%'; $p[] = '%' . $who . '%'; }
if ($tab === 'audit' && get('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditimi_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Koha', 'Përdoruesi', 'Roli', 'Veprimi', 'Objekti', 'Detajet', 'IP'], ';', '"', '\\');
    foreach (qa('SELECT * FROM audit_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 50000', $p) as $r) {
        fputcsv($out, [$r['created_at'], $r['user_name'] ?? 'Prind/sistem', $r['role'], audit_actions()[$r['action']] ?? $r['action'], $r['target'], $r['details'], $r['ip']], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

layout_start('Mbrojtja e të dhënave');
?>
<div class="tabs">
  <a href="?tab=audit" class="<?= $tab === 'audit' ? 'active' : '' ?>">📜 Regjistri i veprimeve</a>
  <a href="?tab=backup" class="<?= $tab === 'backup' ? 'active' : '' ?>">💾 Kopjet rezervë</a>
  <a href="?tab=retention" class="<?= $tab === 'retention' ? 'active' : '' ?>">🧹 Ruajtja & fshirja</a>
  <a href="?tab=consent" class="<?= $tab === 'consent' ? 'active' : '' ?>">✅ Pëlqimet e prindërve</a>
  <a href="?tab=anon" class="<?= $tab === 'anon' ? 'active' : '' ?>">🔒 Anonimizimi</a>
  <a href="<?= e(url('privacy.php')) ?>" target="_blank">🔒 Njoftimi i privatësisë ↗</a>
</div>

<?php if ($tab === 'audit'):
    $rows = qa('SELECT * FROM audit_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 300', $p); ?>
  <form method="get" class="form-inline mb"><input type="hidden" name="tab" value="audit">
    <label>Veprimi<select name="a"><option value="">Të gjitha</option><?php foreach (audit_actions() as $k => $l): ?><option value="<?= e($k) ?>" <?= $af === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Kush / çfarë<input name="who" value="<?= e($who) ?>" placeholder="emri i përdoruesit ose objekti"></label>
    <label>Periudha<select name="days"><?php foreach ([1 => 'Sot', 7 => '7 ditë', 30 => '30 ditë', 90 => '90 ditë', 365 => '1 vit'] as $k => $l): ?><option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <button class="btn btn-primary">Filtro</button>
    <a class="btn" href="?<?= e(http_build_query(['tab' => 'audit', 'a' => $af, 'who' => $who, 'days' => $days, 'export' => 'csv'])) ?>">⬇️ CSV</a>
  </form>
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Koha</th><th>Përdoruesi</th><th>Veprimi</th><th>Objekti</th><th>Detajet</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="small" style="white-space:nowrap"><?= e(fmt_dt($r['created_at'])) ?></td><td class="small"><?= e($r['user_name'] ?? '—') ?><div class="muted"><?= e($r['role'] ?? ($r['action'] === 'parent.view' ? 'prind' : 'sistem')) ?></div></td>
        <td class="small"><?= e(audit_actions()[$r['action']] ?? $r['action']) ?></td><td class="small"><?= e($r['target']) ?></td><td class="small"><?= e($r['details']) ?></td><td class="small muted"><?= e($r['ip']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">Asnjë veprim në këtë periudhë.</td></tr><?php endif; ?></tbody>
  </table></div>

<?php elseif ($tab === 'backup'): $list = backups_list(); ?>
  <section class="card">
    <div class="row-between"><div><h2 style="margin:0">💾 Kopjet rezervë të bazës</h2>
      <p class="muted small" style="margin:4px 0 0">Krijohen automatikisht çdo ditë<?= setting('backup_auto') === '1' ? '' : ' (aktualisht <strong>joaktive</strong>)' ?>; mbahen <?= setting_int('backups_keep') ?> të fundit. Ruhen në dosjen e mbrojtur <code>backups/</code>. Librat (dosja <code>content/books</code>) kopjojini nga cPanel → File Manager.</p></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn btn-primary">＋ Krijo kopje tani</button></form></div>
  </section>
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Skedari</th><th>Krijuar</th><th class="num">Madhësia</th><th></th></tr></thead>
    <tbody><?php foreach ($list as $b): ?>
      <tr><td><code><?= e($b['name']) ?></code> <?= str_contains($b['name'], '_auto') ? '<span class="badge">automatike</span>' : '' ?></td><td class="small"><?= e(date('d.m.Y H:i', $b['time'])) ?></td><td class="num small"><?= e(human_size($b['size'])) ?></td>
        <td class="actions"><a class="btn btn-sm" href="?tab=backup&download=<?= e(urlencode($b['name'])) ?>">⬇️ Shkarko</a>
          <form method="post" class="inline" data-confirm="Ta fshij këtë kopje?"><?= csrf_field() ?><input type="hidden" name="action" value="backup_delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-danger">🗑️</button></form></td></tr>
    <?php endforeach; if (!$list): ?><tr><td colspan="4" class="empty">Nuk ka kopje ende.</td></tr><?php endif; ?></tbody>
  </table></div>
  <p class="small muted mt">♻️ Rikthimi: phpMyAdmin → baza → <strong>Import</strong> → zgjidhni skedarin .sql.gz. Kujdes: rikthimi zëvendëson të dhënat aktuale.</p>

<?php elseif ($tab === 'retention'): ?>
  <section class="card" style="max-width:720px">
    <h2>🧹 Sa kohë ruhen të dhënat</h2>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="retention">
      <label>Klikimet & përdorimi i veçorive fshihen pas (ditë)<input type="number" name="retention_events_days" min="30" max="3650" value="<?= setting_int('retention_events_days') ?>"></label>
      <label>Regjistri i veprimeve (auditimi) fshihet pas (ditë)<input type="number" name="retention_audit_days" min="90" max="3650" value="<?= setting_int('retention_audit_days') ?>"></label>
      <label>Sa kopje rezervë të mbahen<input type="number" name="backups_keep" min="1" max="90" value="<?= setting_int('backups_keep') ?>"></label>
      <label class="checkline"><input type="checkbox" name="backup_auto" value="1" <?= setting('backup_auto') === '1' ? 'checked' : '' ?>> Krijo kopje rezervë automatikisht çdo ditë</label>
      <button class="btn btn-primary">Ruaj</button>
    </form>
    <form method="post" class="mt" data-confirm="Të fshihen tani të dhënat më të vjetra se afatet e mësipërme?"><?= csrf_field() ?><input type="hidden" name="action" value="cleanup"><button class="btn">🧹 Fshij tani të dhënat e vjetra</button></form>
    <p class="small muted">Fshirja bëhet automatikisht një herë në ditë (ose nga <code>tools/cron_daily.php</code> nëse e vendosni si Cron Job në cPanel). Progresi i mësimit dhe llogaritë nuk fshihen automatikisht.</p>
  </section>

<?php elseif ($tab === 'anon'):
    $st = anon_stats();
    $sch = qa("SELECT s.id, s.name, s.city, s.code,
        (SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.school_id=s.id) students,
        (SELECT COUNT(DISTINCT cs.student_id) FROM class_students cs JOIN classes c ON c.id=cs.class_id JOIN users y ON y.id=cs.student_id
           WHERE c.school_id=s.id AND y.full_name NOT REGEXP '^[A-Z]{3}-[A-Z0-9]{1,4}-[0-9]{2,3}-[0-9]{2,3}$') named
        FROM schools s ORDER BY s.city, s.name"); ?>
  <section class="card">
    <h2>🔒 Anonimizimi i nxënësve</h2>
    <p>Në vend të emrit, çdo nxënës ruhet me një kod: <code style="font-size:1.1rem">PRI-IQ-01-05</code> =
      <strong>komuna</strong> (3 shkronja) · <strong>shkolla</strong> (akronim, maks. 3) · <strong>klasa</strong> (01, 02…) · <strong>nxënësi</strong> (01, 02…).
      Edhe përdoruesi bëhet kodi (<code>pri-iq-01-05</code>). <strong>Emrat e vërtetë nuk ruhen në sistem</strong>: ata shkarkohen një herë si listë-çelës (CSV) që e mban mësuesi.</p>
    <div class="grid-3">
      <div class="kpi"><strong><?= $st['total'] ?></strong><span>nxënës gjithsej</span></div>
      <div class="kpi"><strong><?= $st['coded'] ?></strong><span>të koduar</span></div>
      <div class="kpi"><strong><?= $st['total'] - $st['coded'] ?></strong><span>ende me emër</span></div>
    </div>
  </section>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="anon_settings">
    <label class="checkline"><input type="checkbox" name="anonymize_students" value="1" <?= anon_enabled() ? 'checked' : '' ?>> Koduaj automatikisht çdo nxënës të ri (shtim një nga një, listë, import)</label>
    <h3 class="mt">Akronimet e shkollave</h3>
    <p class="small muted">Bosh = gjenerohet automatikisht nga emri (p.sh. "Ylli i Mëngjesit" → YM, "Dielli" → DIE). Ndryshojeni vetëm para anonimizimit, që kodet të mbeten të qëndrueshme.</p>
    <div class="table-wrap"><table class="t">
      <thead><tr><th>Komuna</th><th>Kodi i komunës</th><th>Shkolla</th><th>Akronimi</th><th>Shembull</th><th>Nxënës</th><th>Me emër</th><th></th></tr></thead>
      <tbody><?php foreach ($sch as $s): $auto = anon_school_auto((string)$s['name']); $sc = $s['code'] ?: $auto; ?>
        <tr><td><?= e($s['city'] ?: '—') ?></td><td><code><?= e(anon_muni_code($s['city'])) ?></code></td><td><?= e($s['name']) ?></td>
          <td><input name="code[<?= (int)$s['id'] ?>]" value="<?= e((string)$s['code']) ?>" placeholder="<?= e($auto) ?>" maxlength="3" style="width:5em;text-transform:uppercase"></td>
          <td><code><?= e(anon_muni_code($s['city']) . '-' . $sc . '-01-01') ?></code></td>
          <td><?= (int)$s['students'] ?></td><td><?= (int)$s['named'] ? '<strong>' . (int)$s['named'] . '</strong>' : '0' ?></td>
          <td class="actions"><?php if ((int)$s['named']): ?><button class="btn btn-sm" form="anon<?= (int)$s['id'] ?>">🔒 Anonimizo</button><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <button class="btn btn-primary mt">Ruaj</button>
  </form>
  <?php foreach ($sch as $s): if ((int)$s['named']): ?>
    <form id="anon<?= (int)$s['id'] ?>" method="post" data-confirm="Të zëvendësohen emrat e <?= (int)$s['named'] ?> nxënësve të <?= e($s['name']) ?> me kode? Do të shkarkohet lista-çelës — RUAJENI dhe jepjani mësuesve; emrat nuk ruhen më në sistem."><?= csrf_field() ?><input type="hidden" name="action" value="anon_run"><input type="hidden" name="school" value="<?= (int)$s['id'] ?>"></form>
  <?php endif; endforeach; ?>
  <?php if ($st['total'] - $st['coded'] > 0): ?>
  <form method="post" class="card" data-confirm="Të anonimizohen TË GJITHË nxënësit me emër (<?= $st['total'] - $st['coded'] ?>)? Do të shkarkohet një listë-çelës e vetme — ruajeni në vend të sigurt."><?= csrf_field() ?><input type="hidden" name="action" value="anon_run">
    <h3>Anonimizo të gjithë nxënësit ekzistues</h3>
    <p class="small">Lista-çelës (CSV) përmban: komunën, shkollën, mësuesin, klasën, <strong>kodin</strong>, <strong>emrin e vërtetë</strong>, përdoruesin, fjalëkalimin dhe fotot e hyrjes. Ndajeni sipas mësuesit dhe jepjani secilit vetëm klasën e vet.</p>
    <button class="btn btn-primary">🔒 Anonimizo të gjithë dhe shkarko listën-çelës</button>
  </form>
  <?php endif; ?>
  <?php if ($st['total'] > 0 && $st['total'] === $st['coded']): ?>
  <form method="post" class="card" data-confirm="Të fshihen TË GJITHA kopjet rezervë të vjetra (që përmbajnë emrat) dhe të krijohet një kopje e re pa emra? Sigurohuni që e keni ruajtur listën-çelës."><?= csrf_field() ?><input type="hidden" name="action" value="purge_backups">
    <h3>✅ Të gjithë nxënësit janë të koduar</h3>
    <p class="small">Hapi i fundit: fshini kopjet rezervë të vjetra, që përmbajnë ende emrat, dhe krijoni një kopje të re pa emra.</p>
    <button class="btn btn-danger">🗑️ Fshi kopjet e vjetra dhe krijo kopje të re</button>
  </form>
  <?php endif; ?>
  <div class="flash flash-info">ℹ️ Kopjet rezervë të krijuara para anonimizimit përmbajnë ende emrat. Pasi të keni ruajtur listën-çelës, fshini kopjet e vjetra te <a href="?tab=backup">💾 Kopjet rezervë</a>. Nxënësit hyjnë si më parë: me përdoruesin e ri, ose me kodin e klasës → <strong>Nr. i tyre</strong> → 3 fotot.</div>

<?php else:
    $rows = qa("SELECT s.id, s.full_name, s.special_needs, s.special_needs_type, s.sen_consent_at, s.sen_consent_by, sc.name school,
        (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM classes c JOIN class_students cs ON cs.class_id=c.id WHERE cs.student_id=s.id) classes
        FROM users s LEFT JOIN schools sc ON sc.id=s.school_id WHERE s.role='student' AND (s.special_needs IS NOT NULL OR s.sen_consent_at IS NOT NULL) ORDER BY sc.name, s.full_name");
    $missing = count(array_filter($rows, fn($r) => (int)$r['special_needs'] === 1 && !$r['sen_consent_at'])); ?>
  <p class="muted">Lista e nxënësve me të dhëna për nevojat e veçanta dhe statusi i pëlqimit të prindit. <?= $missing ? '<strong style="color:var(--err)">⚠️ ' . $missing . ' nxënës kanë të dhëna NV pa pëlqim të regjistruar</strong> (të futura para kësaj veçorie) — plotësoni pëlqimin ose fshini të dhënat.' : '' ?></p>
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Nxënësi</th><th>Shkolla · Klasa</th><th>Nevoja të veçanta</th><th>Pëlqimi</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['full_name']) ?></td><td class="small"><?= e($r['school']) ?> · <?= e($r['classes']) ?></td>
        <td class="small"><?= (int)$r['special_needs'] === 1 ? 'Po' . ($r['special_needs_type'] ? ' · ' . e($r['special_needs_type']) : '') : 'Jo' ?></td>
        <td class="small"><?= $r['sen_consent_at'] ? '✅ ' . e($r['sen_consent_by']) . ' · ' . e(fmt_dt($r['sen_consent_at'], false)) : ((int)$r['special_needs'] === 1 ? '<span class="badge badge-open">⚠️ Mungon</span>' : '—') ?></td>
        <td class="actions"><?php if ((int)$r['special_needs'] === 1 || $r['sen_consent_at']): ?><form method="post" class="inline" data-confirm="Të fshihen të dhënat e nevojave të veçanta për <?= e($r['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="purge_sen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger">🗑️ Fshi të dhënat NV</button></form><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="empty">Nuk ka të dhëna për nevoja të veçanta.</td></tr><?php endif; ?></tbody>
  </table></div>
<?php endif; ?>
<?php layout_end();
