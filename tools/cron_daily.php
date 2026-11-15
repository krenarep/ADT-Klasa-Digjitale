<?php
// Cron ditor: php tools/cron_daily.php  (cPanel → Cron Jobs, p.sh. çdo ditë në 02:00)
if (PHP_SAPI !== 'cli') exit("Vetëm nga rreshti i komandës.\n");
require __DIR__ . '/../inc/bootstrap.php';
if (db_pending_migrations()) exit("Baza ka nevojë për përditësim: hapni admin/perditeso_db.php\n");
foreach (run_daily_tasks(true) as $k => $v) echo "$k: $v\n";
