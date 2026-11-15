<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$classes = qa('SELECT * FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);
$classId = geti('class', $classes ? (int)$classes[0]['id'] : 0);
if ($classId && !teacher_owns_class((int)$u['id'], $classId)) $classId = 0;
$books = $classId ? qa('SELECT b.* FROM books b JOIN class_books cb ON cb.book_id=b.id WHERE cb.class_id=? ORDER BY b.title', [$classId]) : [];
$bookId = geti('book', $books ? (int)$books[0]['id'] : 0);
$book = null; foreach ($books as $b) if ((int)$b['id'] === $bookId) $book = $b;
$th = setting_int('cert_threshold') ?: 90;
$students = $classId ? qa('SELECT s.* FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? AND s.active=1 ORDER BY s.full_name', [$classId]) : [];
$prog = $book ? progress_for(array_column($students, 'id'), $bookId) : [];
$eligible = array_values(array_filter($students, fn($s) => ($prog[(int)$s['id']]['progress'] ?? 0) >= $th));
$schoolName = (string)qv('SELECT name FROM schools WHERE id=?', [$u['school_id']]);

if (get('print') && $book) {
    $only = geti('student');
    ?><!DOCTYPE html><html lang="sq"><head><meta charset="utf-8"><title>Certifikatat · <?= e($book['title']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4"><style>body{background:#eee;padding:16px}@media print{body{background:#fff;padding:0}.no-print{display:none}@page{size:A4 landscape;margin:8mm}}</style></head><body>
    <p class="no-print" style="text-align:center"><button class="btn btn-primary btn-lg" onclick="window.print()">🖨️ Printo</button></p>
    <?php foreach ($eligible as $s) if (!$only || (int)$s['id'] === $only) echo certificate_html($s, $book, $u['full_name'], $schoolName); ?>
    </body></html><?php
    exit;
}

$sel = '<form method="get" class="row"><select name="class" onchange="this.form.book.value=0;this.form.submit()" aria-label="Klasa">';
foreach ($classes as $c) $sel .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>🏷️ ' . e($c['name']) . '</option>';
$sel .= '</select><select name="book" onchange="this.form.submit()" aria-label="Libri">';
foreach ($books as $b) $sel .= '<option value="' . $b['id'] . '"' . ($bookId === (int)$b['id'] ? ' selected' : '') . '>📚 ' . e($b['title']) . '</option>';
$sel .= '</select></form>';
if ($eligible) $sel .= '<a class="btn btn-primary" target="_blank" href="?class=' . $classId . '&book=' . $bookId . '&print=1">🖨️ Printo të gjitha (' . count($eligible) . ')</a>';

layout_start('Certifikatat', ['actions' => $sel]);
?>
<p class="muted">Certifikata jepet kur nxënësi arrin <strong><?= $th ?>%</strong> progres në libër (pragu ndryshohet nga administratori). Çdo certifikatë ka kod verifikimi.</p>
<?php if (!$book): ?><div class="card empty">Zgjidhni klasën dhe librin.</div><?php else: ?>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Nxënësi</th><th style="min-width:200px">Progresi</th><th>Certifikata</th><th></th></tr></thead>
  <tbody><?php foreach ($students as $s): $p = $prog[(int)$s['id']]; $ok = $p['progress'] >= $th; ?>
    <tr><td><?= e($s['avatar']) ?> <?= e($s['full_name']) ?></td><td><div class="pcell"><?= progress_bar($p['progress']) ?><span class="small"><?= $p['progress'] ?>%</span></div></td>
      <td><?= $ok ? '<span class="badge badge-closed">🏆 E fituar</span> <code class="small">' . e(certificate_code((int)$s['id'], $bookId)) . '</code>' : '<span class="muted small">edhe ' . ($th - $p['progress']) . '%</span>' ?></td>
      <td class="actions"><?php if ($ok): ?><a class="btn btn-sm" target="_blank" href="?class=<?= $classId ?>&book=<?= $bookId ?>&print=1&student=<?= (int)$s['id'] ?>">🖨️</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; layout_end();
