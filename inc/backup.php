<?php
declare(strict_types=1);

function backup_dir(): string {
    $d = APP_ROOT . '/backups';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
    return $d;
}

/** Krijon kopje rezervë SQL (e ngjeshur .gz) të gjithë bazës. Kthen emrin e skedarit. */
function backup_create(string $kind = 'manual'): string {
    @set_time_limit(600);
    $dir = backup_dir();
    if (!is_writable($dir)) throw new RuntimeException('Dosja backups/ nuk mund të shkruhet nga serveri.');
    $gz = function_exists('gzopen');
    $name = 'kopje_' . date('Ymd_His') . '_' . preg_replace('/[^a-z]/', '', $kind) . '.sql' . ($gz ? '.gz' : '');
    $path = $dir . '/' . $name;
    $fh = $gz ? gzopen($path, 'wb6') : fopen($path, 'wb');
    $w = $gz ? fn($s) => gzwrite($fh, $s) : fn($s) => fwrite($fh, $s);
    $pdo = db();
    $w("-- Klasa Digjitale — kopje rezervë\n-- Krijuar: " . date('Y-m-d H:i:s') . "\n-- Rikthimi: phpMyAdmin → baza → Import\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $w("DROP TABLE IF EXISTS `$table`;\n$create;\n\n");
        $offset = 0;
        while (true) {
            $rows = $pdo->query("SELECT * FROM `$table` LIMIT 500 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;
            $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
            $vals = [];
            foreach ($rows as $r) {
                $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            }
            $w("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n");
            $offset += 500;
        }
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    $gz ? gzclose($fh) : fclose($fh);
    backup_prune();
    audit('backup', $name, $kind === 'auto' ? 'Kopje automatike ditore' : 'Kopje manuale');
    return $name;
}

function backups_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/kopje_*.sql*') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function backup_prune(): void {
    $keep = max(1, setting_int('backups_keep'));
    foreach (array_slice(backups_list(), $keep) as $b) @unlink(backup_dir() . '/' . $b['name']);
}

function backup_path(string $name): ?string {
    if (!preg_match('/^kopje_\d{8}_\d{6}_[a-z]+\.sql(\.gz)?$/', $name)) return null;
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

function human_size(int $b): string {
    return $b > 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}
