<?php
// Përditësimi i bazës së të dhënave nga paneli i administratorit (pa dosjen install/)
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

$log = [];
if (is_post()) {
    csrf_check();
    foreach (db_migrations() as $key => $m) {
        try {
            if (db_migration_done($m)) {
                $log[] = ['ok', $m['title'], 'Ekzistonte tashmë — asgjë nuk u ndryshua.'];
                continue;
            }
            db_run_migration($m);
            audit('db.upgrade', $key);
            $log[] = db_migration_done($m)
                ? ['ok', $m['title'], ucfirst(db_migration_target($m)) . ' u krijua me sukses.']
                : ['err', $m['title'], 'Komanda u ekzekutua, por ndryshimi nuk u gjet.'];
        } catch (Throwable $ex) {
            $log[] = ['err', $m['title'], $ex->getMessage()];
        }
    }
}

$checks = [];
foreach (db_migrations() as $key => $m) $checks[] = [$m['title'], db_migration_target($m), db_migration_done($m)];
$allOk = !in_array(false, array_column($checks, 2), true);
$dbName = (string)qv('SELECT DATABASE()');
$ver = (string)qv('SELECT VERSION()');

layout_start('Përditësimi i bazës së të dhënave');
?>
<section class="card">
  <h2>🗄️ Gjendja e bazës</h2>
  <dl class="kv">
    <dt>Baza</dt><dd><code><?= e($dbName) ?></code></dd>
    <dt>Serveri</dt><dd><?= e($ver) ?> · PHP <?= e(PHP_VERSION) ?></dd>
  </dl>
  <div class="table-wrap mt"><table class="t">
    <thead><tr><th>Hapi</th><th>Ndryshimi</th><th>Statusi</th></tr></thead>
    <tbody><?php foreach ($checks as $i => [$title, $table, $exists]): ?>
      <tr><td><?= $i + 1 ?>. <?= e($title) ?></td><td><code><?= e($table) ?></code></td>
        <td><?= $exists ? '<span class="badge badge-closed">✅ Gati</span>' : '<span class="badge badge-open">⏳ Mungon</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<?php if ($log): ?>
<section class="card">
  <h2>📋 Rezultati</h2>
  <?php foreach ($log as [$type, $title, $msg]): ?>
    <div class="flash flash-<?= $type ?>"><?= $type === 'ok' ? '✅' : '⚠️' ?> <strong><?= e($title) ?>:</strong> <?= e($msg) ?></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
  <?php if ($allOk): ?>
    <h2>🎉 Baza është e përditësuar</h2>
    <p>Të gjitha ndryshimet janë zbatuar. <a class="btn btn-primary" href="index.php">Vazhdo te paneli →</a></p>
  <?php else: ?>
    <h2>▶️ Përditëso tani</h2>
    <p>Shtyp butonin — krijohen vetëm tabelat dhe kolonat që mungojnë. Të dhënat ekzistuese (shkollat, nxënësit, progresi) <strong>nuk preken</strong>.</p>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-lg">🗄️ Përditëso bazën</button></form>
    <p class="small muted mt">Nëse shfaqet gabim leje (p.sh. <code>CREATE command denied</code>), përdorni rrugën me phpMyAdmin: importoni skedarin <code>install/perditesimi_db.sql</code>.</p>
  <?php endif; ?>
</section>
<?php layout_end();
