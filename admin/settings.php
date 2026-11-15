<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

if (is_post()) {
    csrf_check();
    $map = ['alert_inactive_days' => [2, 60], 'alert_low_score' => [10, 95], 'alert_min_graded' => [1, 20], 'cert_threshold' => [50, 100], 'small_cell_min' => [0, 20]];
    foreach ($map as $k => [$min, $max]) set_setting($k, (string)max($min, min($max, posti($k))));
    set_setting('parent_links', post('parent_links') ? '1' : '0');
    set_setting('picture_login', post('picture_login') ? '1' : '0');
    if (!post('parent_links')) q('UPDATE users SET parent_token=NULL WHERE parent_token IS NOT NULL');
    audit('settings', 'Cilësimet e sistemit', http_build_query(array_intersect_key($_POST, $map + ['parent_links' => 1])));
    flash('ok', 'Cilësimet u ruajtën.');
    redirect('admin/settings.php');
}
if (get('run') === 'daily') {
    $r = run_daily_tasks(true);
    flash('ok', 'Detyrat ditore u ekzekutuan: ' . implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($r), $r)));
    redirect('admin/settings.php');
}

layout_start('Cilësimet');
?>
<form method="post" class="grid-2"><?= csrf_field() ?>
  <section class="card">
    <h2>🔔 Njoftimet automatike për mësuesit</h2>
    <div class="form">
      <label>Paralajmëro kur nxënësi nuk lexon prej (ditë)<input type="number" name="alert_inactive_days" min="2" max="60" value="<?= setting_int('alert_inactive_days') ?>"></label>
      <label>Paralajmëro kur saktësia bie nën (%)<input type="number" name="alert_low_score" min="10" max="95" value="<?= setting_int('alert_low_score') ?>"></label>
      <label>…pas sa ushtrimeve të vlerësuara (14 ditët e fundit)<input type="number" name="alert_min_graded" min="1" max="20" value="<?= setting_int('alert_min_graded') ?>"></label>
    </div>
  </section>
  <section class="card">
    <h2>🏅 Certifikatat & 👪 prindërit</h2>
    <div class="form">
      <label>🔒 Raportet: fshih grupet me më pak se (nxënës) <span class="help">rekomandohet 5 · 0 = mos fshih</span><input type="number" name="small_cell_min" min="0" max="20" value="<?= small_cell_min() ?>"></label>
      <label>Progresi i nevojshëm për certifikatë (%)<input type="number" name="cert_threshold" min="50" max="100" value="<?= setting_int('cert_threshold') ?>"></label>
      <label class="checkline"><input type="checkbox" name="parent_links" value="1" <?= setting('parent_links') === '1' ? 'checked' : '' ?>> Lejo linqet private për prindër (pa fjalëkalim)</label>
      <label class="checkline"><input type="checkbox" name="picture_login" value="1" <?= picture_login_enabled() ? 'checked' : '' ?>> Lejo hyrjen me fotografi (kod klase + 3 foto) — kur është joaktive, nxënësit hyjnë vetëm me përdorues dhe fjalëkalim</label>
      <p class="help" style="margin:0">Nëse e çaktivizoni, të gjitha linqet ekzistuese anulohen; mësuesit mund t'i printojnë raportet.</p>
    </div>
  </section>
  <div><button class="btn btn-primary btn-lg">Ruaj cilësimet</button></div>
</form>
<section class="card mt">
  <h2>⏰ Detyrat ditore</h2>
  <p>Çdo ditë sistemi: krijon njoftimet për mësuesit, fshin të dhënat e vjetra sipas afateve dhe bën kopjen rezervë. Ekzekutohen automatikisht gjatë përdorimit (ekzekutimi i fundit: <strong><?= e(setting('daily_last') ?: '—') ?></strong>).</p>
  <p class="small muted">Për saktësi më të madhe, në cPanel → <strong>Cron Jobs</strong> shtoni (çdo ditë në 02:00):<br><code>php <?= e(APP_ROOT) ?>/tools/cron_daily.php</code></p>
  <a class="btn" href="?run=daily">▶️ Ekzekuto tani</a>
</section>
<?php layout_end();
