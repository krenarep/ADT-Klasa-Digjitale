<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');

$sy = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
$valid = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;
$from = $valid(get('from')) ?? $sy . '-09-01';
$to = $valid(get('to')) ?? date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$classes = qa('SELECT id, name FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);
$classId = geti('class');
if ($classId && !teacher_owns_class((int)$u['id'], $classId)) $classId = 0;
$books = qa('SELECT DISTINCT b.id, b.title FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN classes c ON c.id=cb.class_id WHERE c.teacher_id=? ORDER BY b.title', [$u['id']]);
$bookId = geti('book');
if ($bookId && !in_array($bookId, array_map('intval', array_column($books, 'id')), true)) $bookId = 0;
$className = ''; foreach ($classes as $c) if ((int)$c['id'] === $classId) $className = $c['name'];
$bookName = ''; foreach ($books as $b) if ((int)$b['id'] === $bookId) $bookName = $b['title'];

$filtersText = ['Mësuesi: ' . $u['full_name'], 'Shkolla: ' . ($u['school_name'] ?? '—'), 'Periudha: ' . date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)),
    'Klasa: ' . ($className ?: 'të gjitha'), 'Libri: ' . ($bookName ?: 'të gjithë')];
$title = $className ? 'Raporti i klasës ' . $className : 'Raporti i klasave të mia';

// mësuesi: progresi, koha, kuizet dhe struktura e klasës — pa klikime/qasshmëri
$R = report_build(['from' => $from, 'to' => $to, 'teacher_id' => (int)$u['id'], 'class_id' => $classId, 'book_id' => $bookId, 'a11y' => false, 'admin' => false]);
$R['sections'] = array_values(array_filter($R['sections'], fn($s) => !in_array($s['key'], ['tongue'], true)));

if (get('format') === 'xlsx') audit('export', $title . ' (Excel)', implode(' · ', $filtersText));
if (get('format') === 'xlsx') report_xlsx($R, $title, $filtersText, 'raporti_klases_' . $from . '_' . $to . '.xlsx');

$extra = '<label>Klasa<select name="class"><option value="0">Të gjitha klasat e mia</option>';
foreach ($classes as $c) $extra .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
$extra .= '</select></label><label>Libri<select name="book"><option value="0">Të gjithë</option>';
foreach ($books as $b) $extra .= '<option value="' . $b['id'] . '"' . ($bookId === (int)$b['id'] ? ' selected' : '') . '>' . e($b['title']) . '</option>';
$extra .= '</select></label>';

$q = http_build_query(['from' => $from, 'to' => $to, 'class' => $classId, 'book' => $bookId]);
layout_start('Raporti i klasës', ['actions' => '<button class="btn" onclick="window.print()">🖨️ Printo / PDF</button><a class="btn btn-primary" href="?' . e($q) . '&format=xlsx">⬇️ Excel</a>']);
include APP_ROOT . '/inc/partials/report_filters.php';
echo report_html($R, $title, $filtersText);
layout_end();
