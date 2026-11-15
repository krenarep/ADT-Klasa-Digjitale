<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$tid = (int)$u['id'];
if (db_pending_migrations()) { layout_start('Vlerësimi'); echo '<div class="card empty">⏳ Kjo faqe do të jetë gati pasi administratori ta klikojë <b>🗄️ Përditëso bazën</b>.</div>'; layout_end(); exit; }
$tab = in_array(get('tab'), ['todo', 'done', 'work'], true) ? get('tab') : 'todo';
$classId = geti('class');
$bookId = geti('book');
$qs = '?tab=' . $tab . ($classId ? '&class=' . $classId : '') . ($bookId ? '&book=' . $bookId : '');

if (is_post()) {
    csrf_check();
    if (post('action') === 'bulk') {
        $n = handle_bulk_verify($u, (array)($_POST['ids'] ?? []));
        flash('ok', $n ? "✔ U konfirmuan $n rezultate automatike." : 'Nuk u zgjodh asnjë rezultat automatik.');
    } elseif (in_array(post('action'), ['work_grade', 'work_approve_all'], true)) {
        handle_work_grade_post($u);
    } else {
        handle_grade_post($u);
    }
    redirect('teacher/grading.php' . $qs);
}

$classes = qa('SELECT id, name FROM classes WHERE teacher_id=? ORDER BY name', [$tid]);
$books = qa('SELECT DISTINCT b.id, b.title FROM books b JOIN class_books cb ON cb.book_id=b.id JOIN classes c ON c.id=cb.class_id WHERE c.teacher_id=? ORDER BY b.title', [$tid]);
$scope = 'q.student_id IN (SELECT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=?' . ($classId ? ' AND c.id=' . $classId : '') . ')' . ($bookId ? ' AND q.book_id=' . $bookId : '');
$todo = (int)qv('SELECT COUNT(*) FROM quiz_attempts q WHERE ' . pending_verify_sql() . ' AND ' . $scope, [$tid]);

if ($tab === 'work') {
    $wscope = str_replace('q.', 'w.', $scope);
    $work = qa("SELECT w.*, s.full_name, s.avatar, b.title book, bp.page_number, bp.title ptitle FROM student_work w
        JOIN users s ON s.id=w.student_id JOIN books b ON b.id=w.book_id LEFT JOIN book_pages bp ON bp.book_id=w.book_id AND bp.section_id=w.section_id
        WHERE $wscope AND TRIM(COALESCE(w.value,'')) <> '' ORDER BY s.full_name, b.title, bp.position, w.item_key LIMIT 600", [$tid]);
} else {
    $where = $tab === 'todo' ? pending_verify_sql() : 'q.verified_at IS NOT NULL AND q.id = (SELECT MAX(q2.id) FROM quiz_attempts q2 WHERE q2.student_id=q.student_id AND q2.book_id=q.book_id AND q2.section_id=q.section_id)';
    $rows = qa("SELECT q.*, s.full_name, s.avatar, b.title book, bp.page_number, bp.title ptitle FROM quiz_attempts q
        JOIN users s ON s.id=q.student_id JOIN books b ON b.id=q.book_id LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
        WHERE $where AND $scope ORDER BY s.full_name, b.title, bp.position, q.id LIMIT 500", [$tid]);
}

layout_start('Vlerësimi dhe verifikimi');
?>
<p class="muted">Çdo ndërveprim i nxënësit me ushtrimet e librit ruhet këtu: përgjigjet që libri i tregon menjëherë (saktë/gabim), tekstet që nxënësi i shkruan dhe i dërgon me «Dërgo», dhe të gjitha tentativat. Rezultati që jep libri është <strong>automatik</strong> — ju e <strong>konfirmoni ✔</strong> ose e <strong>ndryshoni ✍️</strong> dhe mund të lini koment. Përgjigjet e hapura i vlerësoni gjithmonë ju.</p>
<p class="small muted">Vlerësimet janë të grupuara sipas nxënësve: klikoni emrin për ta hapur listën e tij, pastaj rreshtin për t'i parë përgjigjet dhe për ta vlerësuar. Pasi ta verifikoni, nxënësi e sheh vlerësimin te butoni <b>⭐ Vlerësimet</b>.</p>
<div class="tabs">
  <a href="?tab=todo<?= $classId ? '&class=' . $classId : '' ?><?= $bookId ? '&book=' . $bookId : '' ?>" class="<?= $tab === 'todo' ? 'active' : '' ?>">⏳ Për verifikim (<?= $todo ?>)</a>
  <a href="?tab=done<?= $classId ? '&class=' . $classId : '' ?><?= $bookId ? '&book=' . $bookId : '' ?>" class="<?= $tab === 'done' ? 'active' : '' ?>">✅ Të verifikuara</a>
  <a href="?tab=work<?= $classId ? '&class=' . $classId : '' ?><?= $bookId ? '&book=' . $bookId : '' ?>" class="<?= $tab === 'work' ? 'active' : '' ?>">📝 Tekstet & shënimet<?= ($pw = pending_work_count($tid)) ? ' (' . $pw . ')' : '' ?></a>
</div>
<form method="get" class="row mb"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <select name="class" onchange="this.form.submit()"><option value="">Të gjitha klasat</option><?php foreach ($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $classId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <select name="book" onchange="this.form.submit()"><option value="">Të gjithë librat</option><?php foreach ($books as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $bookId === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['title']) ?></option><?php endforeach; ?></select>
</form>

<?php if ($tab === 'work'): ?>
  <p class="small muted">Këtu shihni çka kanë shkruar nxënësit në libër — edhe kur nuk e kanë shtypur «Dërgo» — dhe shënimet e tyre (🗒️ blloku i shënimeve). Klikoni rreshtin për ta lexuar tekstin e plotë, pastaj <b>vlerësojeni</b> (0–100%), <b>aprovojeni ✔</b> dhe lini koment. Nxënësi e sheh te ⭐ Vlerësimet. Nëse nxënësi e ndryshon tekstin më vonë, ai kthehet për vlerësim.</p>
  <?= work_grouped_html($work, 'teacher') ?>
  <?php if (!$work): ?><div class="card empty">Ende nuk ka tekste të shkruara nga nxënësit.</div><?php endif; ?>

<?php else: ?>
  <?php $autoIds = array_column(array_filter($rows, fn($a) => $a['score_pct'] !== null), 'id'); ?>
  <?php if ($tab === 'todo' && $autoIds): ?>
    <form method="post" id="bulkForm" class="card row-between" style="margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="action" value="bulk">
      <span class="small">Zgjidhni rezultatet automatike që i keni kontrolluar dhe konfirmojini me një klik.
        <label class="checkline" style="display:inline-flex;margin-left:8px"><input type="checkbox" onclick="document.querySelectorAll('.bulk-id').forEach(c=>c.checked=this.checked)"> Zgjidh të gjitha (<?= count($autoIds) ?>)</label></span>
      <button class="btn btn-sm btn-primary">✔ Konfirmo të zgjedhurat</button>
    </form>
  <?php endif; ?>
  <?= grading_grouped_html($rows, 'teacher', $tab === 'todo') ?>
  <?php if (!$rows): ?><div class="card empty"><?= $tab === 'todo' ? '🎉 Nuk ka ushtrime që presin verifikim.' : 'Ende asnjë verifikim.' ?></div><?php endif; ?>
<?php endif; ?>
<?php layout_end();
