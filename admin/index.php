<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

$days = in_array(geti('days', 30), [7, 30, 90], true) ? geti('days', 30) : 30;
$schoolId = geti('school');
$since = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
$schools = qa('SELECT id, name FROM schools ORDER BY name');

// filtri i shkollës për nxënësit
$sf = $schoolId ? ' AND st.school_id = ' . (int)$schoolId : '';

$counts = [
    'schools'  => (int)qv('SELECT COUNT(*) FROM schools WHERE active=1'),
    'teachers' => (int)qv("SELECT COUNT(*) FROM users st WHERE role='teacher' AND active=1 $sf"),
    'students' => (int)qv("SELECT COUNT(*) FROM users st WHERE role='student' AND active=1 $sf"),
    'classes'  => (int)qv('SELECT COUNT(*) FROM classes' . ($schoolId ? ' WHERE school_id=' . (int)$schoolId : '')),
    'books'    => (int)qv('SELECT COUNT(*) FROM books'),
];
$usage = q1("SELECT COUNT(DISTINCT ud.student_id) active, COALESCE(SUM(ud.seconds),0) secs FROM usage_daily ud JOIN users st ON st.id=ud.student_id WHERE ud.day >= ? $sf", [$since]);
$today = (int)qv("SELECT COUNT(DISTINCT ud.student_id) FROM usage_daily ud JOIN users st ON st.id=ud.student_id WHERE ud.day = CURDATE() $sf");
$att = q1("SELECT COUNT(*) n, AVG(qa.score_pct) avg_s FROM quiz_attempts qa JOIN users st ON st.id=qa.student_id WHERE qa.created_at >= ? $sf", [$since]);
$openTickets = (int)qv("SELECT COUNT(*) FROM tickets t WHERE status<>'closed'" . ($schoolId ? ' AND school_id=' . (int)$schoolId : ''));
$adminOpen = (int)qv("SELECT COUNT(*) FROM tickets WHERE recipient_role='admin' AND status<>'closed'");

$daily = [];
foreach (qa("SELECT ud.day, SUM(ud.seconds) s FROM usage_daily ud JOIN users st ON st.id=ud.student_id WHERE ud.day >= ? $sf GROUP BY ud.day", [$since]) as $r) $daily[$r['day']] = (int)$r['s'];
$activeDaily = [];
foreach (qa("SELECT ud.day, COUNT(DISTINCT ud.student_id) n FROM usage_daily ud JOIN users st ON st.id=ud.student_id WHERE ud.day >= ? $sf GROUP BY ud.day", [$since]) as $r) $activeDaily[$r['day']] = (int)$r['n'];
$activeSeries = [];
for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $activeSeries[] = ['label' => date('d.m', strtotime($d)), 'value' => $activeDaily[$d] ?? 0]; }

$perSchool = qa("SELECT s.id, s.name, s.city,
    (SELECT COUNT(*) FROM users WHERE school_id=s.id AND role='teacher' AND active=1) teachers,
    (SELECT COUNT(*) FROM users WHERE school_id=s.id AND role='student' AND active=1) students,
    (SELECT COUNT(DISTINCT ud.student_id) FROM usage_daily ud JOIN users x ON x.id=ud.student_id WHERE x.school_id=s.id AND ud.day >= ?) active,
    (SELECT COALESCE(SUM(ud.seconds),0) FROM usage_daily ud JOIN users x ON x.id=ud.student_id WHERE x.school_id=s.id AND ud.day >= ?) secs,
    (SELECT COUNT(*) FROM quiz_attempts qa JOIN users x ON x.id=qa.student_id WHERE x.school_id=s.id AND qa.created_at >= ?) attempts,
    (SELECT AVG(qa.score_pct) FROM quiz_attempts qa JOIN users x ON x.id=qa.student_id WHERE x.school_id=s.id AND qa.created_at >= ?) avg_s,
    (SELECT COUNT(*) FROM tickets WHERE school_id=s.id AND status<>'closed') tickets
  FROM schools s " . ($schoolId ? 'WHERE s.id=' . (int)$schoolId : '') . " ORDER BY s.name", [$since, $since, $since, $since]);

$topBooks = qa("SELECT b.id, b.title, COALESCE(SUM(ud.seconds),0) secs, COUNT(DISTINCT ud.student_id) readers
  FROM books b LEFT JOIN usage_daily ud ON ud.book_id=b.id AND ud.day >= ? LEFT JOIN users st ON st.id=ud.student_id
  WHERE 1=1 " . ($schoolId ? ' AND (st.school_id=' . (int)$schoolId . ' OR st.id IS NULL)' : '') . "
  GROUP BY b.id ORDER BY secs DESC LIMIT 6", [$since]);
$maxBook = max(1, ...array_map(fn($b) => (int)$b['secs'], $topBooks ?: [['secs' => 1]]));

$recentTickets = qa(TICKET_LIST_SQL . ($schoolId ? ' WHERE t.school_id=' . (int)$schoolId : '') . ' ORDER BY t.updated_at DESC LIMIT 6');

$filters = '<form class="row" method="get"><select name="school" onchange="this.form.submit()" aria-label="Shkolla"><option value="0">Të gjitha shkollat</option>';
foreach ($schools as $s) $filters .= '<option value="' . $s['id'] . '"' . ($schoolId === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
$filters .= '</select><select name="days" onchange="this.form.submit()" aria-label="Periudha">';
foreach ([7 => '7 ditët e fundit', 30 => '30 ditët e fundit', 90 => '90 ditët e fundit'] as $k => $l) $filters .= '<option value="' . $k . '"' . ($days === $k ? ' selected' : '') . '>' . $l . '</option>';
$filters .= '</select></form>';

layout_start('Paneli i administratorit', ['actions' => $filters]);
?>
<div class="stats">
  <div class="stat"><div class="lbl">🏫 Shkolla</div><div class="val"><?= $counts['schools'] ?></div><div class="sub"><?= $counts['classes'] ?> klasa</div></div>
  <div class="stat"><div class="lbl">👩‍🏫 Mësues</div><div class="val"><?= $counts['teachers'] ?></div></div>
  <div class="stat"><div class="lbl">🧒 Nxënës</div><div class="val"><?= $counts['students'] ?></div><div class="sub"><?= $today ?> aktivë sot</div></div>
  <div class="stat"><div class="lbl">⚡ Nxënës aktivë</div><div class="val"><?= (int)$usage['active'] ?></div><div class="sub"><?= pct((int)$usage['active'], $counts['students']) ?>% e nxënësve · <?= $days ?> ditë</div></div>
  <div class="stat"><div class="lbl">⏱️ Koha e mësimit</div><div class="val"><?= e(fmt_duration((int)$usage['secs'])) ?></div><div class="sub">mesatarisht <?= e(fmt_duration($usage['active'] ? intdiv((int)$usage['secs'], (int)$usage['active']) : 0)) ?> / nxënës</div></div>
  <div class="stat"><div class="lbl">📝 Tentativa ushtrimesh</div><div class="val"><?= (int)$att['n'] ?></div><div class="sub">saktësia mesatare <?= $att['avg_s'] !== null ? (int)round((float)$att['avg_s']) . '%' : '—' ?></div></div>
  <div class="stat"><div class="lbl">🎫 Tiketa të hapura</div><div class="val"><?= $openTickets ?></div><div class="sub"><?= $adminOpen ?> për administratorin</div></div>
  <div class="stat"><div class="lbl">📚 Libra</div><div class="val"><?= $counts['books'] ?></div></div>
  <?php $a11yIn = "'" . implode("','", a11y_features()) . "'";
  $a11yU = q1("SELECT COUNT(*) n, COUNT(DISTINCT e.student_id) st FROM interaction_events e JOIN users st ON st.id=e.student_id WHERE e.created_at >= ? AND e.feature IN ($a11yIn) $sf", [$since . ' 00:00:00']);
  $clk = (int)qv("SELECT COUNT(*) FROM interaction_events e JOIN users st ON st.id=e.student_id WHERE e.created_at >= ? $sf", [$since . ' 00:00:00']); ?>
  <a class="stat" href="usage.php?days=<?= $days ?>&school=<?= $schoolId ?>" style="text-decoration:none;color:inherit"><div class="lbl">🖱️ Klikime</div><div class="val"><?= number_format($clk, 0, ',', '.') ?></div><div class="sub">Shiko analizën →</div></a>
  <a class="stat" href="usage.php?days=<?= $days ?>&school=<?= $schoolId ?>" style="text-decoration:none;color:inherit"><div class="lbl">♿ Qasshmëria</div><div class="val"><?= (int)$a11yU['st'] ?> nxënës</div><div class="sub"><?= (int)$a11yU['n'] ?> përdorime të veçorive</div></a>
</div>

<div class="grid-2">
  <section class="card">
    <h2>⏱️ Minuta mësimi në ditë</h2>
    <?= bar_chart(daily_series($daily, $days), 'min', 'Minutat e mësimit në ditë') ?>
  </section>
  <section class="card">
    <h2>🧒 Nxënës aktivë në ditë</h2>
    <?= bar_chart($activeSeries, 'nxënës', 'Nxënës aktivë në ditë') ?>
  </section>
</div>

<section class="card">
  <div class="row-between"><h2>🏫 Përdorimi sipas shkollës</h2><a class="btn btn-sm" href="schools.php">Menaxho shkollat</a></div>
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Shkolla</th><th class="num">Mësues</th><th class="num">Nxënës</th><th class="num">Aktivë</th><th>Pjesëmarrja</th><th class="num">Koha</th><th class="num">Tentativa</th><th class="num">Saktësia</th><th class="num">Tiketa</th></tr></thead>
    <tbody>
    <?php foreach ($perSchool as $s): $p = pct((int)$s['active'], (int)$s['students']); ?>
      <tr>
        <td><strong><?= e($s['name']) ?></strong><div class="small muted"><?= e($s['city']) ?></div></td>
        <td class="num"><?= (int)$s['teachers'] ?></td><td class="num"><?= (int)$s['students'] ?></td><td class="num"><?= (int)$s['active'] ?></td>
        <td><div class="pcell"><?= progress_bar($p) ?><span class="small"><?= $p ?>%</span></div></td>
        <td class="num"><?= e(fmt_duration((int)$s['secs'])) ?></td>
        <td class="num"><?= (int)$s['attempts'] ?></td>
        <td class="num"><?= score_html($s['avg_s'] !== null ? (int)round((float)$s['avg_s']) : null) ?></td>
        <td class="num"><?= (int)$s['tickets'] ?></td>
      </tr>
    <?php endforeach; if (!$perSchool): ?><tr><td colspan="9" class="empty">Shtoni shkollën e parë te <a href="schools.php">Shkollat</a>.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<div class="grid-2">
  <section class="card">
    <h2>📚 Librat më të përdorur</h2>
    <div class="hbars">
      <?php foreach ($topBooks as $b): ?>
        <div class="hbar"><span><?= e($b['title']) ?></span><?= progress_bar(pct((int)$b['secs'], $maxBook)) ?><span class="small muted"><?= e(fmt_duration((int)$b['secs'])) ?> · <?= (int)$b['readers'] ?> lexues</span></div>
      <?php endforeach; if (!$topBooks): ?><p class="muted">Nuk ka libra ende.</p><?php endif; ?>
    </div>
  </section>
  <section class="card">
    <div class="row-between"><h2>🎫 Tiketat e fundit</h2><a class="btn btn-sm" href="tickets.php">Shiko të gjitha</a></div>
    <?php if (!$recentTickets): ?><p class="muted">Nuk ka tiketa.</p><?php endif; ?>
    <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px">
      <?php foreach ($recentTickets as $t): ?>
        <li class="row-between"><a href="tickets.php?id=<?= (int)$t['id'] ?>"><?= ticket_categories()[$t['category']][0] ?? '💬' ?> <?= e($t['subject']) ?></a>
          <span class="small muted"><?= e($t['creator_name']) ?> → <?= $t['recipient_role'] === 'admin' ? 'Admin' : e($t['assignee_name'] ?? '') ?> · <?= status_badge($t['status']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
<?php layout_end();
