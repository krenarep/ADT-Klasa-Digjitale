<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('admin', 'teacher');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shabllon_nxenesit.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Emri i plotë', 'Gjinia', 'Nevoja të veçanta', 'Pëlqimi i prindit', 'Prindi', 'Gjuha amtare', 'Përdoruesi', 'Fjalëkalimi', 'Kodi i klasës'], ';', '"', '\\');
$code = $u['role'] === 'teacher' ? (string)qv('SELECT class_code FROM classes WHERE teacher_id=? ORDER BY id LIMIT 1', [$u['id']]) : (string)qv('SELECT class_code FROM classes ORDER BY id LIMIT 1');
fputcsv($out, ['Nxënësja Shembull', 'V', 'Jo', '', '', 'Shqip', '', '', $code], ';', '"', '\\');
fputcsv($out, ['Nxënësi Shembull', 'D', 'Vështirësi në të lexuar (disleksi)', 'Po', 'Prindi Shembull', 'Shqip', 'nxenesi.shembull', 'Ndrysho123', $code], ';', '"', '\\');
fclose($out);
