<?php
// Vlerësimet — ndërveprimi nxënës ↔ mësues (pamje mbikëqyrëse për administratorin, vetëm lexim)
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');
$schoolId = geti('school'); $teacherId = geti('teacher'); $classId = geti('class');
$status = in_array(get('status'), ['pending', 'verified', 'all'], true) ? get('status') : 'all';
$tab = get('tab') === 'work' ? 'work' : 'grades';

$w = ['1=1']; $args = [];
if ($schoolId) { $w[] = 'c.school_id=?'; $args[] = $schoolId; }
if ($teacherId) { $w[] = 'c.teacher_id=?'; $args[] = $teacherId; }
if ($classId) { $w[] = 'c.id=?'; $args[] = $classId; }
$scopeSql = implode(' AND ', $w);
$latest = 'q.id = (SELECT MAX(q2.id) FROM quiz_attempts q2 WHERE q2.student_id=q.student_id AND q2.book_id=q.book_id AND q2.section_id=q.section_id)';
// klasa e nxënësit (e para), mësuesi dhe shkolla
$join = 'JOIN users s ON s.id=q.student_id JOIN books b ON b.id=q.book_id
    JOIN class_students cs ON cs.student_id=q.student_id JOIN classes c ON c.id=cs.class_id AND c.id=(SELECT MIN(cs2.class_id) FROM class_students cs2 WHERE cs2.student_id=q.student_id)
    JOIN users t ON t.id=c.teacher_id LEFT JOIN schools sc ON sc.id=c.school_id';

$stats = q1("SELECT COUNT(*) n, SUM(q.verified_at IS NOT NULL) v, SUM(q.verified_at IS NULL) p, SUM(q.graded_by IS NOT NULL AND q.auto_score IS NOT NULL AND q.auto_score<>q.score_pct) adj,
    SUM(q.teacher_note IS NOT NULL AND q.teacher_note<>'') notes, SUM(q.verified_at IS NOT NULL AND q.student_seen=1) seen
    FROM quiz_attempts q $join WHERE $latest AND $scopeSql", $args) ?: [];

if ($tab === 'work') {
    $work = qa("SELECT w.*, s.full_name, s.avatar, b.title book, bp.page_number, bp.title ptitle, c.name class_name, t.full_name teacher_name, v.full_name verifier_name FROM student_work w
        LEFT JOIN users v ON v.id=w.verified_by
        JOIN users s ON s.id=w.student_id JOIN books b ON b.id=w.book_id LEFT JOIN book_pages bp ON bp.book_id=w.book_id AND bp.section_id=w.section_id
        JOIN class_students cs ON cs.student_id=w.student_id JOIN classes c ON c.id=cs.class_id AND c.id=(SELECT MIN(cs2.class_id) FROM class_students cs2 WHERE cs2.student_id=w.student_id)
        JOIN users t ON t.id=c.teacher_id
        WHERE $scopeSql AND TRIM(COALESCE(w.value,'')) <> '' ORDER BY s.full_name, b.title, bp.position, w.item_key LIMIT 800", $args);
} else {
    $st = $status === 'pending' ? ' AND q.verified_at IS NULL' : ($status === 'verified' ? ' AND q.verified_at IS NOT NULL' : '');
    $rows = qa("SELECT q.*, s.full_name, s.avatar, b.title book, bp.page_number, bp.title ptitle, c.name class_name, t.full_name teacher_name, sc.name school_name, v.full_name verifier_name
        FROM quiz_attempts q $join LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id LEFT JOIN users v ON v.id=q.verified_by
        WHERE $latest AND $scopeSql $st ORDER BY sc.name, t.full_name, s.full_name, b.title, bp.position LIMIT 800", $args);
}
$schools = qa('SELECT id, name FROM schools ORDER BY name');
$teachers = qa("SELECT id, full_name FROM users WHERE role='teacher' AND active=1" . ($schoolId ? ' AND school_id=' . $schoolId : '') . ' ORDER BY full_name');
$classes = qa('SELECT c.id, c.name, t.full_name teacher FROM classes c JOIN users t ON t.id=c.teacher_id WHERE 1=1' . ($schoolId ? ' AND c.school_id=' . $schoolId : '') . ($teacherId ? ' AND c.teacher_id=' . $teacherId : '') . ' ORDER BY c.name');
$qs = fn(array $o) => '?' . http_build_query(array_filter($o + ['tab' => $tab, 'school' => $schoolId, 'teacher' => $teacherId, 'class' => $classId, 'status' => $status]));
$n = (int)($stats['n'] ?? 0);

layout_start('Vlerësimet · ndërveprimi nxënës–mësues');
?>
<p class="muted">Këtu shihni gjithë ndërveprimin: çka kanë dërguar nxënësit nga librat, rezultatin automatik, verifikimin dhe komentin e mësuesit, dhe a e ka parë nxënësi vlerësimin. Pamja është vetëm për lexim — verifikimin e bën mësuesi.</p>
<div class="stats">
  <div class="stat"><div class="lbl">📝 Ushtrime të dërguara</div><div class="val"><?= $n ?></div><div class="sub">tentativa e fundit për çdo faqe</div></div>
  <div class="stat"><div class="lbl">✔ Të verifikuara nga mësuesit</div><div class="val"><?= (int)($stats['v'] ?? 0) ?></div><div class="sub"><?= $n ? round(100 * (int)$stats['v'] / $n) : 0 ?>% e të gjithave</div></div>
  <div class="stat"><div class="lbl">⏳ Presin verifikim</div><div class="val"><?= (int)($stats['p'] ?? 0) ?></div></div>
  <div class="stat"><div class="lbl">✍️ Rezultate të ndryshuara</div><div class="val"><?= (int)($stats['adj'] ?? 0) ?></div><div class="sub"><?= (int)($stats['notes'] ?? 0) ?> me koment</div></div>
  <div class="stat"><div class="lbl">👀 Të para nga nxënësit</div><div class="val"><?= (int)($stats['seen'] ?? 0) ?></div><div class="sub">nga <?= (int)($stats['v'] ?? 0) ?> të verifikuara</div></div>
</div>
<div class="tabs">
  <a href="<?= e($qs(['tab' => 'grades'])) ?>" class="<?= $tab === 'grades' ? 'active' : '' ?>">✍️ Vlerësimet sipas nxënësve</a>
  <a href="<?= e($qs(['tab' => 'work'])) ?>" class="<?= $tab === 'work' ? 'active' : '' ?>">📝 Tekstet & shënimet e nxënësve</a>
</div>
<form method="get" class="row mb"><input type="hidden" name="tab" value="<?= e($tab) ?>">
  <select name="school" onchange="this.form.teacher.value='';this.form.class.value='';this.form.submit()" style="width:auto"><option value="">Të gjitha shkollat</option><?php foreach ($schools as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $schoolId === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select>
  <select name="teacher" onchange="this.form.class.value='';this.form.submit()" style="width:auto"><option value="">Të gjithë mësuesit</option><?php foreach ($teachers as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $teacherId === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select>
  <select name="class" onchange="this.form.submit()" style="width:auto"><option value="">Të gjitha klasat</option><?php foreach ($classes as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $classId === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name'] . ' · ' . $x['teacher']) ?></option><?php endforeach; ?></select>
  <?php if ($tab === 'grades'): ?><select name="status" onchange="this.form.submit()" style="width:auto"><?php foreach (['all' => 'Të gjitha', 'pending' => '⏳ Presin verifikim', 'verified' => '✔ Të verifikuara'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?php endif; ?>
</form>
<?php if ($tab === 'work'): ?>
  <?= work_grouped_html($work, 'admin') ?>
  <?php if (!$work): ?><div class="card empty">Ende nuk ka tekste të shkruara.</div><?php endif; ?>
<?php else: ?>
  <?= grading_grouped_html($rows, 'admin') ?>
  <?php if (!$rows): ?><div class="card empty">Nuk ka vlerësime për këta filtra.</div><?php endif; ?>
<?php endif; ?>
<?php layout_end();
