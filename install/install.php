<?php
declare(strict_types=1);
// Instaluesi: krijon config.php, tabelat dhe llogarinë e parë të administratorit.
$root = dirname(__DIR__);
$cfgPath = $root . '/config.php';
session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$installed = false;
if (is_file($cfgPath)) {
    $c = require $cfgPath;
    try {
        $pdo = new PDO("mysql:host={$c['db']['host']};port={$c['db']['port']};dbname={$c['db']['name']};charset=utf8mb4", $c['db']['user'], $c['db']['pass']);
        $installed = (bool)$pdo->query("SELECT 1 FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
    } catch (Throwable) {}
}

$err = '';
$done = false;
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = [
        'host' => trim($_POST['host'] ?? '127.0.0.1'),
        'port' => (int)($_POST['port'] ?? 3306),
        'name' => preg_replace('/[^A-Za-z0-9_]/', '', $_POST['name'] ?? 'klasa_digjitale'),
        'user' => trim($_POST['user'] ?? 'root'),
        'pass' => (string)($_POST['pass'] ?? ''),
    ];
    $base = rtrim(trim($_POST['base_url'] ?? ''), '/');
    $aName = trim($_POST['admin_name'] ?? '');
    $aUser = trim($_POST['admin_user'] ?? '');
    $aPass = (string)($_POST['admin_pass'] ?? '');
    try {
        if ($aName === '' || $aUser === '' || strlen($aPass) < 8) {
            throw new RuntimeException('Plotësoni të dhënat e administratorit (fjalëkalimi min. 8 karaktere).');
        }
        $pdo = new PDO("mysql:host={$db['host']};port={$db['port']};charset=utf8mb4", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$db['name']}`");
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/schema.sql'));
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        $st = $pdo->prepare("INSERT INTO users (role, full_name, username, password_hash) VALUES ('admin', ?, ?, ?)");
        $st->execute([$aName, $aUser, password_hash($aPass, PASSWORD_DEFAULT)]);

        $cfg = "<?php\nreturn " . var_export([
            'db' => $db,
            'base_url' => $base,
            'app_name' => trim($_POST['app_name'] ?? '') ?: 'Klasa Digjitale',
            'timezone' => 'Europe/Belgrade',
            'max_upload_mb' => 200,
        ], true) . ";\n";
        if (@file_put_contents($cfgPath, $cfg) === false) {
            $err = 'Baza u krijua, por config.php nuk mund të shkruhej. Krijojeni manualisht me këtë përmbajtje:';
            $manual = $cfg;
        } else {
            $done = true;
        }
        @mkdir($root . '/content/books', 0775, true);
    } catch (Throwable $ex) {
        $err = 'Gabim: ' . $ex->getMessage();
    }
}
$guessBase = rtrim(str_replace('/install/install.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
?><!DOCTYPE html>
<html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalimi · Klasa Digjitale</title>
<link rel="stylesheet" href="../assets/css/app.css"></head>
<body class="role-guest"><main class="guest"><div class="card" style="max-width:640px;margin:40px auto">
<h1>📘 Instalimi i Klasa Digjitale</h1>
<?php if ($installed): ?>
  <p>✅ Aplikacioni është instaluar tashmë. <a class="btn btn-primary" href="../login.php">Shko te hyrja</a></p>
  <p class="muted small">Për siguri, fshini ose mbroni dosjen <code>install/</code> në server.</p>
<?php elseif ($done): ?>
  <p>✅ Instalimi përfundoi me sukses!</p>
  <p><a class="btn btn-primary" href="../login.php">Hyr si administrator ➜</a></p>
  <p class="muted small">Për siguri, fshini ose mbroni dosjen <code>install/</code> në server.</p>
<?php else: ?>
  <?php if ($err): ?><div class="flash flash-err"><?= h($err) ?></div><?php if (!empty($manual)): ?><pre><?= h($manual) ?></pre><?php endif; endif; ?>
  <form method="post" class="form">
    <h3>Baza e të dhënave (MySQL / MariaDB)</h3>
    <div class="grid-2">
      <label>Host<input name="host" value="<?= h($_POST['host'] ?? '127.0.0.1') ?>"></label>
      <label>Porti<input name="port" value="<?= h($_POST['port'] ?? '3306') ?>"></label>
      <label>Emri i bazës<input name="name" value="<?= h($_POST['name'] ?? 'klasa_digjitale') ?>"></label>
      <label>Përdoruesi<input name="user" value="<?= h($_POST['user'] ?? 'root') ?>"></label>
    </div>
    <label>Fjalëkalimi i bazës<input type="password" name="pass"></label>
    <label>URL bazë (nën-dosja, p.sh. <code>/klasa</code>; bosh nëse në rrënjë)<input name="base_url" value="<?= h($_POST['base_url'] ?? $guessBase) ?>"></label>
    <label>Emri i aplikacionit<input name="app_name" value="<?= h($_POST['app_name'] ?? 'Klasa Digjitale') ?>"></label>
    <h3>Administratori i parë</h3>
    <label>Emri i plotë<input name="admin_name" required value="<?= h($_POST['admin_name'] ?? '') ?>"></label>
    <label>Përdoruesi<input name="admin_user" required value="<?= h($_POST['admin_user'] ?? 'admin') ?>"></label>
    <label>Fjalëkalimi (min. 8)<input type="password" name="admin_pass" minlength="8" required></label>
    <button class="btn btn-primary btn-lg">Instalo</button>
  </form>
<?php endif; ?>
</div></main></body></html>
