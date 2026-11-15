<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher');
$tid = (int)$u['id'];

$myStudents = "SELECT DISTINCT cs.student_id FROM class_students cs JOIN classes c ON c.id=cs.class_id WHERE c.teacher_id=$tid";
$since7 = date('Y-m-d', strtotime('-6 day'));
$stats = [
    'classes' => (int)qv('SELECT COUNT(*) FROM classes WHERE teacher_id=?', [$tid]),
    'students' => (int)qv("SELECT COUNT(*) FROM ($myStudents) x"),
    'books' => (int)qv('SELECT COUNT(DISTINCT cb.book_id) FROM class_books cb JOIN classes c ON c.id=cb.class_id WHERE c.teacher_id=?', [$tid]),
    'today' => (int)qv("SELECT COUNT(DISTINCT student_id) FROM usage_daily WHERE day=CURDATE() AND student_id IN ($myStudents)"),
    'active7' => (int)qv("SELECT COUNT(DISTINCT student_id) FROM usage_daily WHERE day>=? AND student_id IN ($myStudents)", [$since7]),
    'secs7' => (int)qv("SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE day>=? AND student_id IN ($myStudents)", [$since7]),
    'att7' => (int)qv("SELECT COUNT(*) FROM quiz_attempts WHERE created_at>=? AND student_id IN ($myStudents)", [$since7]),
    'tickets' => (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='teacher' AND assigned_to=? AND status<>'closed'", [$tid]),
];
$daily = [];
foreach (qa("SELECT day, SUM(seconds) s FROM usage_daily WHERE day>=? AND student_id IN ($myStudents) GROUP BY day", [date('Y-m-d', strtotime('-13 day'))]) as $r) $daily[$r['day']] = (int)$r['s'];

$classRows = qa("SELECT c.*, (SELECT COUNT(*) FROM class_students WHERE class_id=c.id) n,
   (SELECT COUNT(DISTINCT ud.student_id) FROM usage_daily ud JOIN class_students cs ON cs.student_id=ud.student_id WHERE cs.class_id=c.id AND ud.day>=?) act,
   (SELECT COALESCE(SUM(ud.seconds),0) FROM usage_daily ud JOIN class_students cs ON cs.student_id=ud.student_id WHERE cs.class_id=c.id AND ud.day>=?) secs
  FROM classes c WHERE c.teacher_id=? ORDER BY c.grade, c.name", [$since7, $since7, $tid]);

$inactive = qa("SELECT s.id, s.full_name, s.avatar, (SELECT MAX(day) FROM usage_daily WHERE student_id=s.id) last_day
  FROM users s WHERE s.id IN ($myStudents) AND s.active=1 HAVING last_day IS NULL OR last_day < ? ORDER BY last_day LIMIT 8", [date('Y-m-d', strtotime('-7 day'))]);
$openAnswers = qa("SELECT q.id, q.created_at, q.student_id, q.book_id, s.full_name, s.avatar, bp.title, bp.page_number FROM quiz_attempts q
  JOIN users s ON s.id=q.student_id LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
  WHERE q.student_id IN ($myStudents) AND q.score_pct IS NULL AND q.graded_by IS NULL ORDER BY q.created_at DESC LIMIT 8");
$lowScores = qa("SELECT s.id, s.full_name, s.avatar, q.book_id, ROUND(AVG(q.score_pct)) avg_s, COUNT(*) n FROM quiz_attempts q JOIN users s ON s.id=q.student_id
  WHERE q.student_id IN ($myStudents) AND q.score_pct IS NOT NULL AND q.created_at >= ? GROUP BY s.id, q.book_id HAVING avg_s < 50 ORDER BY avg_s LIMIT 6", [date('Y-m-d', strtotime('-30 day'))]);
$tickets = qa(TICKET_LIST_SQL . " WHERE t.recipient_role='teacher' AND t.assigned_to=$tid AND t.status<>'closed' ORDER BY t.updated_at DESC LIMIT 6");

$alerts = qa('SELECT * FROM alerts WHERE teacher_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT 5', [$tid]);
$alertCount = alerts_unread($tid);
$assignOpen = qa('SELECT a.*, c.name class_name FROM assignments a JOIN classes c ON c.id=a.class_id WHERE c.teacher_id=? AND a.due_date >= CURDATE() ORDER BY a.due_date LIMIT 4', [$tid]);
layout_start('Mirë se erdhët, ' . explode(' ', $u['full_name'])[0] . '!');
?>
<?php try { echo journey_css() . '<div style="position:relative">' . journey_bar_html(journey_state((int)$u['id']), true) . '<a class="btn btn-primary btn-sm" style="position:absolute;top:10px;right:14px" href="' . e(url('teacher/journey.php')) . '">🧭 Rrugëtimi im →</a></div>'; } catch (Throwable) {} ?>
<?php if ($alerts): ?>
<section class="card" style="border-left:5px solid #f0a020">
  <div class="row-between"><h2 style="margin:0">🔔 Njoftime që kërkojnë vëmendje (<?= $alertCount ?>)</h2><a class="btn btn-sm" href="alerts.php">Shiko të gjitha →</a></div>
  <ul style="margin:10px 0 0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:6px">
    <?php foreach ($alerts as $al): ?><li><?= alert_kinds()[$al['kind']][0] ?? '🔔' ?> <?= e($al['message']) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<div class="stats">
  <div class="stat"><div class="lbl">🏷️ Klasa</div><div class="val"><?= $stats['classes'] ?></div><div class="sub"><?= $stats['books'] ?> libra të caktuar</div></div>
  <div class="stat"><div class="lbl">🧒 Nxënës</div><div class="val"><?= $stats['students'] ?></div><div class="sub"><?= $stats['today'] ?> aktivë sot</div></div>
  <div class="stat"><div class="lbl">⚡ Aktivë (7 ditë)</div><div class="val"><?= $stats['active7'] ?></div><?= progress_bar(pct($stats['active7'], $stats['students'])) ?></div>
  <div class="stat"><div class="lbl">⏱️ Koha (7 ditë)</div><div class="val"><?= e(fmt_duration($stats['secs7'])) ?></div></div>
  <div class="stat"><div class="lbl">📝 Ushtrime (7 ditë)</div><div class="val"><?= $stats['att7'] ?></div></div>
  <div class="stat"><div class="lbl">🎫 Tiketa të hapura</div><div class="val"><?= $stats['tickets'] ?></div><div class="sub"><a href="tickets.php">Shiko →</a></div></div>
</div>

<?php if (!$stats['classes']): ?>
<section class="card">
  <h2>🚀 Si të filloni</h2>
  <ol style="font-size:1.05rem;line-height:2">
    <li><a href="classes.php">Krijoni një klasë</a> (p.sh. III-a)</li>
    <li><a href="students.php">Shtoni nxënësit</a> — një nga një ose disa njëherësh — dhe printoni kartelat e hyrjes</li>
    <li><a href="books.php">Ngarkoni librin SCORM nga ADT Studio</a> dhe caktojeni klasës</li>
    <li>Ndiqni <a href="progress.php">progresin</a>, kohën dhe tentativat e ushtrimeve</li>
  </ol>
</section>
<?php endif; ?>

<?php if ($assignOpen): ?>
<section class="card">
  <div class="row-between"><h2 style="margin:0">📌 Detyrat aktive</h2><a class="btn btn-sm" href="assignments.php">Menaxho →</a></div>
  <div class="hbars mt">
  <?php foreach ($assignOpen as $as): $ids = array_map('intval', array_column(qa('SELECT student_id FROM class_students WHERE class_id=?', [$as['class_id']]), 'student_id'));
      $pr = assignment_progress($as, $ids); $dn = count(array_filter($pr, fn($p) => $p['status'] === 'done')); ?>
    <div class="hbar"><a href="assignments.php?id=<?= (int)$as['id'] ?>"><?= e($as['title']) ?> · <?= e($as['class_name']) ?></a><?= progress_bar(pct($dn, count($ids))) ?><span class="small muted"><?= $dn ?>/<?= count($ids) ?> · <?= e(due_label($as['due_date'])) ?></span></div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="grid-2">
  <section class="card"><h2>⏱️ Minutat e mësimit — 14 ditët e fundit</h2><?= bar_chart(daily_series($daily, 14), 'min', 'Minutat e mësimit në ditë') ?></section>
  <section class="card">
    <h2>🏷️ Klasat (7 ditët e fundit)</h2>
    <div class="hbars">
      <?php foreach ($classRows as $c): $p = pct((int)$c['act'], (int)$c['n']); ?>
        <div class="hbar"><a href="progress.php?class=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a><?= progress_bar($p) ?><span class="small muted"><?= (int)$c['act'] ?>/<?= (int)$c['n'] ?> aktivë · <?= e(fmt_duration((int)$c['secs'])) ?></span></div>
      <?php endforeach; if (!$classRows): ?><p class="muted">Nuk keni klasa ende.</p><?php endif; ?>
    </div>
  </section>
</div>

<div class="grid-3">
  <section class="card">
    <h2>✍️ Përgjigje të hapura për t'u parë</h2>
    <?php foreach ($openAnswers as $a): ?>
      <p style="margin:0 0 10px"><?= e($a['avatar']) ?> <a href="grading.php"><strong><?= e($a['full_name']) ?></strong></a><br>
        <span class="small muted"><?= $a['page_number'] ? 'f. ' . (int)$a['page_number'] . ' · ' : '' ?><?= e(mb_strimwidth((string)$a['title'], 0, 40, '…')) ?> · <?= e(ago($a['created_at'])) ?></span></p>
    <?php endforeach; if (!$openAnswers): ?><p class="muted">Asnjë përgjigje e re. 👍</p><?php endif; ?>
  </section>
  <section class="card">
    <h2>💤 Joaktivë mbi 7 ditë</h2>
    <?php foreach ($inactive as $s): ?>
      <p style="margin:0 0 8px"><?= e($s['avatar']) ?> <a href="student.php?id=<?= (int)$s['id'] ?>"><?= e($s['full_name']) ?></a> <span class="small muted">· <?= $s['last_day'] ? e(fmt_dt($s['last_day'], false)) : 'asnjëherë' ?></span></p>
    <?php endforeach; if (!$inactive): ?><p class="muted">Të gjithë janë aktivë. 🎉</p><?php endif; ?>
    <?php if ($lowScores): ?><h3 class="mt">🎯 Kanë nevojë për ndihmë (&lt;50%)</h3>
      <?php foreach ($lowScores as $s): ?><p style="margin:0 0 8px"><?= e($s['avatar']) ?> <a href="student.php?id=<?= (int)$s['id'] ?>&book=<?= (int)$s['book_id'] ?>"><?= e($s['full_name']) ?></a> <?= score_html((int)$s['avg_s']) ?></p><?php endforeach; ?>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2>🎫 Tiketat e nxënësve</h2>
    <?php foreach ($tickets as $t): ?>
      <p style="margin:0 0 10px"><a href="tickets.php?id=<?= (int)$t['id'] ?>"><?= $t['unread_recipient'] ? '🔵 ' : '' ?><?= ticket_categories()[$t['category']][0] ?? '' ?> <?= e($t['subject']) ?></a><br><span class="small muted"><?= e($t['creator_name']) ?> · <?= e(ago($t['updated_at'])) ?></span></p>
    <?php endforeach; if (!$tickets): ?><p class="muted">Asnjë tiketë e hapur.</p><?php endif; ?>
  </section>
</div>
<?php layout_end();
