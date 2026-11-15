<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');

$classes = qa('SELECT * FROM classes WHERE teacher_id=? ORDER BY grade, name', [$u['id']]);
$classId = geti('class');
$bookId = geti('book');
if (!$classId && $bookId) $classId = (int)qv('SELECT cb.class_id FROM class_books cb JOIN classes c ON c.id=cb.class_id WHERE c.teacher_id=? AND cb.book_id=? LIMIT 1', [$u['id'], $bookId]);
if (!$classId && $classes) $classId = (int)$classes[0]['id'];
if ($classId && !teacher_owns_class((int)$u['id'], $classId)) $classId = 0;
$books = $classId ? qa('SELECT b.* FROM books b JOIN class_books cb ON cb.book_id=b.id WHERE cb.class_id=? ORDER BY b.title', [$classId]) : [];
if ($books && !in_array($bookId, array_map('intval', array_column($books, 'id')), true)) $bookId = (int)$books[0]['id'];
$book = null;
foreach ($books as $b) if ((int)$b['id'] === $bookId) $book = $b;
$view = get('view', 'students') === 'activities' ? 'activities' : 'students';

$students = $classId ? qa('SELECT s.id, s.full_name, s.avatar FROM users s JOIN class_students cs ON cs.student_id=s.id WHERE cs.class_id=? ORDER BY s.full_name', [$classId]) : [];
$prog = $book ? progress_for(array_column($students, 'id'), $bookId) : [];

$sel = '<form method="get" class="row"><select name="class" onchange="this.form.book.value=0;this.form.submit()" aria-label="Klasa">';
foreach ($classes as $c) $sel .= '<option value="' . $c['id'] . '"' . ($classId === (int)$c['id'] ? ' selected' : '') . '>🏷️ ' . e($c['name']) . '</option>';
$sel .= '</select><select name="book" onchange="this.form.submit()" aria-label="Libri">';
foreach ($books as $b) $sel .= '<option value="' . $b['id'] . '"' . ($bookId === (int)$b['id'] ? ' selected' : '') . '>📚 ' . e($b['title']) . '</option>';
$sel .= '</select><input type="hidden" name="view" value="' . $view . '"></form>';

layout_start('Progresi i nxënësve', ['actions' => $sel]);

if (!$classes) { echo '<div class="card empty">Krijoni një <a href="classes.php">klasë</a> fillimisht.</div>'; layout_end(); exit; }
if (!$book) { echo '<div class="card empty">📚 Kjo klasë nuk ka libra të caktuar. <a href="books.php">Caktoni një libër</a>.</div>'; layout_end(); exit; }

$n = count($students);
$started = count(array_filter($prog, fn($p) => $p['pages'] > 0));
$avgProg = $n ? (int)round(array_sum(array_column($prog, 'progress')) / $n) : 0;
$totalSec = array_sum(array_column($prog, 'seconds'));
$scores = array_filter(array_column($prog, 'avg_score'), fn($s) => $s !== null);
$avgScore = $scores ? (int)round(array_sum($scores) / count($scores)) : null;
$attempts = array_sum(array_column($prog, 'attempts'));
$sessions = array_sum(array_column($prog, 'sessions'));
?>
<div class="stats">
  <div class="stat"><div class="lbl">🧒 Kanë filluar</div><div class="val"><?= $started ?>/<?= $n ?></div><div class="sub"><?= $n - $started ?> ende pa filluar</div></div>
  <div class="stat"><div class="lbl">📈 Progresi mesatar</div><div class="val"><?= $avgProg ?>%</div><?= progress_bar($avgProg) ?></div>
  <div class="stat"><div class="lbl">⏱️ Koha gjithsej</div><div class="val"><?= e(fmt_duration($totalSec)) ?></div><div class="sub">≈ <?= e(fmt_duration($started ? intdiv($totalSec, $started) : 0)) ?> / nxënës</div></div>
  <div class="stat"><div class="lbl">🔁 Seanca leximi</div><div class="val"><?= $sessions ?></div><div class="sub">≈ <?= $started ? round($sessions / $started, 1) : 0 ?> / nxënës · mes. <?= e(fmt_duration($sessions ? intdiv($totalSec, $sessions) : 0)) ?></div></div>
  <div class="stat"><div class="lbl">📝 Tentativa</div><div class="val"><?= $attempts ?></div><div class="sub">saktësia mesatare <?= $avgScore !== null ? $avgScore . '%' : '—' ?></div></div>
</div>

<div class="tabs">
  <a href="?<?= e(http_build_query(['class' => $classId, 'book' => $bookId, 'view' => 'students'])) ?>" class="<?= $view === 'students' ? 'active' : '' ?>">🧒 Sipas nxënësit</a>
  <a href="?<?= e(http_build_query(['class' => $classId, 'book' => $bookId, 'view' => 'activities'])) ?>" class="<?= $view === 'activities' ? 'active' : '' ?>">📝 Sipas ushtrimit</a>
</div>

<?php if ($view === 'students'): ?>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Nxënësi</th><th style="min-width:180px">Progresi</th><th class="num">Faqe të lexuara</th><th class="num">Ushtrime</th><th class="num">Tentativa</th><th class="num">Saktësia</th><th class="num">Seanca</th><th class="num">Ditë aktive</th><th class="num">Koha</th><th>Aktiv</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($students as $s): $p = $prog[(int)$s['id']]; ?>
    <tr>
      <td><span aria-hidden="true"><?= e($s['avatar']) ?></span> <a href="student.php?id=<?= (int)$s['id'] ?>&book=<?= $bookId ?>"><strong><?= e($s['full_name']) ?></strong></a></td>
      <td><div class="pcell"><?= progress_bar($p['progress'], 'Progresi i ' . $s['full_name']) ?><span class="small"><?= $p['progress'] ?>%</span></div></td>
      <td class="num"><?= $p['pages'] ?>/<?= $p['page_total'] ?></td>
      <td class="num"><?= $p['acts_done'] ?>/<?= $p['act_total'] ?></td>
      <td class="num"><?= $p['attempts'] ?></td>
      <td class="num"><?= score_html($p['avg_score']) ?></td>
      <td class="num"><?= $p['sessions'] ?></td>
      <td class="num"><?= $p['days'] ?></td>
      <td class="num"><?= e(fmt_duration($p['seconds'])) ?></td>
      <td class="small"><?= e(ago($p['last_seen'])) ?></td>
      <td class="actions"><a class="btn btn-sm" href="student.php?id=<?= (int)$s['id'] ?>&book=<?= $bookId ?>">Detaje →</a></td>
    </tr>
  <?php endforeach; if (!$students): ?><tr><td colspan="11" class="empty">Klasa nuk ka nxënës.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="small muted mt">Progresi = 60% faqet e lexuara + 40% ushtrimet e dërguara. Saktësia = mesatarja e tentativës më të mirë për çdo ushtrim të vlerësuar automatikisht (përgjigjet e hapura i vlerësoni ju te detajet e nxënësit).</p>

<?php else:
    $ids = implode(',', array_map('intval', array_column($students, 'id')) ?: [0]);
    $acts = qa("SELECT bp.section_id, bp.title, bp.page_number, bp.section_type,
        (SELECT COUNT(DISTINCT student_id) FROM quiz_attempts q WHERE q.book_id=bp.book_id AND q.section_id=bp.section_id AND q.student_id IN ($ids)) done,
        (SELECT COUNT(*) FROM quiz_attempts q WHERE q.book_id=bp.book_id AND q.section_id=bp.section_id AND q.student_id IN ($ids)) att,
        (SELECT COALESCE(SUM(seconds),0) FROM page_views v WHERE v.book_id=bp.book_id AND v.section_id=bp.section_id AND v.student_id IN ($ids)) secs
      FROM book_pages bp WHERE bp.book_id=? AND bp.is_activity=1 ORDER BY bp.position", [$bookId]);
    $avgBest = [];
    foreach (qa("SELECT section_id, AVG(best) avg_s FROM (SELECT section_id, student_id, MAX(score_pct) best FROM quiz_attempts
                 WHERE book_id=? AND student_id IN ($ids) AND score_pct IS NOT NULL GROUP BY section_id, student_id) x GROUP BY section_id", [$bookId]) as $r) {
        $avgBest[$r['section_id']] = $r['avg_s'];
    }
    foreach ($acts as &$a) $a['avg_s'] = $avgBest[$a['section_id']] ?? null;
    unset($a); ?>
<div class="table-wrap"><table class="t">
  <thead><tr><th>Faqja</th><th>Ushtrimi</th><th>Lloji</th><th style="min-width:160px">E kanë dërguar</th><th class="num">Tentativa (mes.)</th><th class="num">Saktësia</th><th class="num">Koha mes.</th></tr></thead>
  <tbody>
  <?php foreach ($acts as $a): $done = (int)$a['done']; ?>
    <tr>
      <td><?= $a['page_number'] ? 'f. ' . (int)$a['page_number'] : '—' ?></td>
      <td><?= e($a['title']) ?></td>
      <td class="small"><?= e(section_type_label($a['section_type'])) ?></td>
      <td><div class="pcell"><?= progress_bar(pct($done, $n)) ?><span class="small"><?= $done ?>/<?= $n ?></span></div></td>
      <td class="num"><?= $done ? number_format((int)$a['att'] / $done, 1, ',', '') : '—' ?></td>
      <td class="num"><?= score_html($a['avg_s'] !== null ? (int)round((float)$a['avg_s']) : null) ?></td>
      <td class="num"><?= e(fmt_duration($started ? intdiv((int)$a['secs'], max(1, $started)) : 0)) ?></td>
    </tr>
  <?php endforeach; if (!$acts): ?><tr><td colspan="7" class="empty">Ky libër nuk ka ushtrime të njohura.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<p class="small muted mt">Ushtrimet me saktësi të ulët ose shumë tentativa tregojnë ku nxënësit kanë nevojë për ndihmë.</p>
<?php endif; layout_end();
