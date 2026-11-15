<?php
/**
 * Ngarkimi i librave në copa (chunked upload) — për libra të mëdhenj (100–600 MB).
 *  GET  ?id=<32hex>                       → sa bajt janë marrë (për vazhdim pas ndërprerjes)
 *  POST id, offset, chunk(file), _csrf    → shton copën në fund të skedarit të përkohshëm
 * Skedari i plotë importohet pastaj nga faqja e librave (fusha e fshehur upload_id).
 */
require __DIR__ . '/../inc/bootstrap.php';
$u = current_user();
if (!$u || !in_array($u['role'], ['admin', 'teacher'], true)) json_out(['ok' => false, 'error' => 'auth'], 401);
session_write_close(); // mos blloko faqet e tjera gjatë ngarkimit

$id = (string)($_GET['id'] ?? $_POST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) json_out(['ok' => false, 'error' => 'id'], 400);
$path = upload_chunk_path($id, (int)$u['id']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    clearstatcache();
    // madhësia e copës sipas kufijve të serverit (me rezervë për fushat e tjera)
    $ini = function (string $k): int { $v = trim((string)ini_get($k)); $n = (int)$v; $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n)); };
    $max = min(array_filter([$ini('upload_max_filesize'), $ini('post_max_size')]) ?: [8 << 20]);
    $chunk = max(256 << 10, min(8 << 20, $max - (64 << 10)));
    json_out(['ok' => true, 'received' => is_file($path) ? filesize($path) : 0, 'chunk' => $chunk]);
}

$t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($t) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) json_out(['ok' => false, 'error' => 'csrf'], 419);

$f = $_FILES['chunk'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['ok' => false, 'error' => 'chunk', 'code' => $f['error'] ?? -1], 400);
$offset = (int)($_POST['offset'] ?? -1);
clearstatcache();
$have = is_file($path) ? filesize($path) : 0;
if ($offset !== $have) json_out(['ok' => false, 'error' => 'offset', 'received' => $have], 409);

$limit = (int)(cfg('max_upload_mb', 600) ?: 600) * 1024 * 1024;
if ($have + (int)$f['size'] > max($limit, 600 * 1024 * 1024)) json_out(['ok' => false, 'error' => 'too_big'], 413);

$in = fopen($f['tmp_name'], 'rb'); $out = fopen($path, 'ab');
if (!$in || !$out) json_out(['ok' => false, 'error' => 'write'], 500);
flock($out, LOCK_EX);
stream_copy_to_stream($in, $out);
flock($out, LOCK_UN); fclose($out); fclose($in);
clearstatcache();
json_out(['ok' => true, 'received' => filesize($path)]);
