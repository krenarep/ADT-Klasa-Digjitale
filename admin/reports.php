<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

$sy = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;
$from = $valid(get('from')) ?? $sy . '-09-01';
$to = $valid(get('to')) ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$komuna = get('komuna');
if ($komuna !== '' && !valid_municipality($komuna)) $komuna = '';
$schoolId = geti('school');
$bookId = geti('book');

$schools = qa('SELECT id, name, city FROM schools ORDER BY name');
$books = qa('SELECT id, title FROM books ORDER BY title');
$schoolName = ''; foreach ($schools as $s) if ((int)$s['id'] === $schoolId) $schoolName = $s['name'];
$bookName = ''; foreach ($books as $b) if ((int)$b['id'] === $bookId) $bookName = $b['title'];

$filtersText = ['Periudha: ' . date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)),
    'Komuna: ' . ($komuna ?: 'të gjitha'), 'Shkolla: ' . ($schoolName ?: 'të gjitha'), 'Libri: ' . ($bookName ?: 'të gjithë')];
$title = 'Raport statistikor' . ($komuna ? ' — Komuna ' . $komuna : '') . ($schoolName ? ' — ' . $schoolName : '');

$R = report_build(['from' => $from, 'to' => $to, 'komuna' => $komuna, 'school_id' => $schoolId, 'book_id' => $bookId, 'a11y' => true, 'admin' => true]);

audit(get('format') === 'xlsx' ? 'export' : 'report', $title . ' (' . (get('format') === 'xlsx' ? 'Excel' : 'ekran') . ')', implode(' · ', $filtersText));
if (get('format') === 'xlsx') {
    report_xlsx($R, $title, $filtersText, 'raport_statistikor_' . translit($komuna ?: 'kosove') . '_' . $from . '_' . $to . '.xlsx');
}

$extra = '<label>Komuna<select name="komuna">' . municipality_options($komuna, true, 'Të gjitha') . '</select></label>';
$extra .= '<label>Shkolla<select name="school"><option value="0">Të gjitha</option>';
foreach ($schools as $s) $extra .= '<option value="' . $s['id'] . '"' . ($schoolId === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . ($s['city'] ? ' (' . e($s['city']) . ')' : '') . '</option>';
$extra .= '</select></label><label>Libri<select name="book"><option value="0">Të gjithë</option>';
foreach ($books as $b) $extra .= '<option value="' . $b['id'] . '"' . ($bookId === (int)$b['id'] ? ' selected' : '') . '>' . e($b['title']) . '</option>';
$extra .= '</select></label>';

$q = http_build_query(['from' => $from, 'to' => $to, 'komuna' => $komuna, 'school' => $schoolId, 'book' => $bookId]);
layout_start('Raportet statistikore', ['actions' => '<button class="btn" onclick="window.print()">🖨️ Printo / PDF</button><a class="btn btn-primary" href="?' . e($q) . '&format=xlsx">⬇️ Excel</a>']);
include APP_ROOT . '/inc/partials/report_filters.php';
echo report_html($R, $title, $filtersText);
layout_end();
