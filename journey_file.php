<?php
// Shkarkimi i dokumenteve të rrugëtimit — vetëm mësuesi pronar ose administratori
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$f = q1('SELECT * FROM journey_files WHERE id=?', [geti('id')]);
if (!$f || ($u['role'] === 'teacher' && (int)$f['teacher_id'] !== (int)$u['id'])) { http_response_code(404); exit('Skedari nuk u gjet.'); }
$p = journey_dir() . '/' . basename($f['stored_name']);
if (!is_file($p)) { http_response_code(404); exit('Skedari mungon në server.'); }
$mime = (function_exists('mime_content_type') ? @mime_content_type($p) : null) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($p));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $f['original_name']) . '"; filename*=UTF-8\'\'' . rawurlencode($f['original_name']));
header('Cache-Control: private, no-store');
readfile($p);
