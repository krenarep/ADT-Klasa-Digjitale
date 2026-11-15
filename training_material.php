<?php
// Shkarkimi i materialeve të trajnimit — admini, ose mësuesit e regjistruar në trajnim (ose kur materiali është për të gjithë)
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$m = q1('SELECT * FROM training_materials WHERE id=?', [geti('id')]);
if (!$m || !tmat_can_download($u, $m)) { http_response_code(404); exit('Materiali nuk u gjet.'); }
$p = tmat_dir() . '/' . basename($m['stored_name']);
if (!is_file($p)) { http_response_code(404); exit('Skedari mungon në server.'); }
$ext = strtolower(pathinfo($m['original_name'], PATHINFO_EXTENSION));
$inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'mp4', 'mp3'], true) && !get('dl');
$mime = (function_exists('mime_content_type') ? @mime_content_type($p) : null) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($p));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $m['original_name']) . '"; filename*=UTF-8\'\'' . rawurlencode($m['original_name']));
header('Cache-Control: private, max-age=0');
readfile($p);
