<?php
// Lista e skedarëve të një libri për shkarkim pa internet (Service Worker)
require __DIR__ . '/../inc/bootstrap.php';
$u = current_user();
if (!$u) json_out(['ok' => false, 'error' => 'auth'], 401);
$b = q1('SELECT * FROM books WHERE id=?', [geti('book')]);
if (!$b || ($u['role'] === 'student' && !student_has_book((int)$u['id'], (int)$b['id']))) json_out(['ok' => false, 'error' => 'book'], 403);
$dir = APP_ROOT . '/content/books/' . $b['folder'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $b['folder']) || !is_dir($dir)) json_out(['ok' => false, 'error' => 'missing'], 404);
$files = []; $size = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
    if (preg_match('/(^|\/)\.|\.map$|^AGENTS\.md$|^imsmanifest\.xml$/', $rel)) continue;
    $files[] = url('content/books/' . $b['folder'] . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))));
    $size += $f->getSize();
}
json_out(['ok' => true, 'book' => (int)$b['id'], 'entry' => book_url($b), 'player' => url('student/player.php?book=' . $b['id']), 'files' => $files, 'bytes' => $size]);
