<?php
// Përditësimi tani bëhet nga paneli: admin/perditeso_db.php
// (disa hostingje e bllokojnë dosjen install/ — prandaj u zhvendos)
if (PHP_SAPI === 'cli') {
    require __DIR__ . '/../inc/bootstrap.php';
    foreach (db_migrations() as $k => $m) {
        if (db_migration_done($m)) { echo "= $k (ekziston)\n"; continue; }
        db_run_migration($m); echo "+ $k\n";
    }
    exit("OK\n");
}
header('Location: ../admin/perditeso_db.php');
