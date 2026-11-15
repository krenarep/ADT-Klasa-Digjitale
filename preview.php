<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('admin', 'teacher');
$b = q1('SELECT * FROM books WHERE id=?', [geti('id')]);
if (!$b || ($u['role'] === 'teacher' && !teacher_can_use_book($u, (int)$b['id']))) { http_response_code(404); exit('Libri nuk u gjet.'); }
header('Location: ' . book_url($b));
