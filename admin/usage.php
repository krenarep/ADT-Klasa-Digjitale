<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('admin');

$days = in_array(geti('days', 30), [1, 7, 30, 90, 365], true) ? geti('days', 30) : 30;
$schoolId = geti('school');
$bookId = geti('book');
$feature = get('feature');
$cat = feature_catalog();
if (!isset($cat[$feature])) $feature = '';
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' day'));

// filtrat e përbashkët për interaction_events (e) + users (st)
$w = ['e.created_at >= ?'];
$p = [$since];
if ($schoolId) { $w[] = 'st.school_id = ?'; $p[] = $schoolId; }
if ($bookId) { $w[] = 'e.book_id = ?'; $p[] = $bookId; }
$W = implode(' AND ', $w);
$J = 'FROM interaction_events e JOIN users st ON st.id = e.student_id';

/* ---------- Eksport CSV i të gjitha klikimeve ---------- */
if (get('export') === 'csv') {
    audit('export', 'Klikimet (CSV)', $days . ' ditë');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="klikimet_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data dhe ora', 'Nxënësi', 'Përdoruesi', 'Shkolla', 'Libri', 'Faqja', 'Veçoria', 'Veprimi', 'Elementi', 'Vlera'], ';', '"', '\\');
    $st = db()->prepare("SELECT e.*, st.full_name, st.username, s.name school, b.title book, bp.page_number $J LEFT JOIN schools s ON s.id=st.school_id
        LEFT JOIN books b ON b.id=e.book_id LEFT JOIN book_pages bp ON bp.book_id=e.book_id AND bp.section_id=e.section_id
        WHERE $W" . ($feature ? ' AND e.feature = ' . db()->quote($feature) : '') . ' ORDER BY e.id LIMIT 200000');
    $st->execute($p);
    while ($r = $st->fetch()) {
        fputcsv($out, [$r['created_at'], $r['full_name'], $r['username'], $r['school'], $r['book'], $r['page_number'] ?: $r['section_id'],
            $cat[$r['feature']][1] ?? $r['feature'], $r['action'], $r['label'], $r['value']], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$studentsTotal = (int)qv("SELECT COUNT(*) FROM users st WHERE role='student' AND active=1" . ($schoolId ? ' AND school_id=' . $schoolId : ''));
$tot = q1("SELECT COUNT(*) clicks, COUNT(DISTINCT e.student_id) students $J WHERE $W", $p);
$a11yIn = "'" . implode("','", a11y_features()) . "'";
$a11y = q1("SELECT COUNT(*) uses, COUNT(DISTINCT e.student_id) students $J WHERE $W AND e.feature IN ($a11yIn)", $p);
$sess = q1('SELECT COUNT(*) n, COALESCE(AVG(ls.seconds),0) avg_s FROM learning_sessions ls JOIN users st ON st.id=ls.student_id WHERE ls.started_at >= ?'
    . ($schoolId ? ' AND st.school_id=' . $schoolId : '') . ($bookId ? ' AND ls.book_id=' . $bookId : ''), [$since]);

$byFeature = [];
foreach (qa("SELECT e.feature, COUNT(*) n, COUNT(DISTINCT e.student_id) st, MAX(e.created_at) last $J WHERE $W GROUP BY e.feature", $p) as $r) $byFeature[$r['feature']] = $r;
$daily = []; $dailyA = [];
foreach (qa("SELECT DATE(e.created_at) d, COUNT(*) n, SUM(e.feature IN ($a11yIn)) a $J WHERE $W GROUP BY d", $p) as $r) { $daily[$r['d']] = (int)$r['n']; $dailyA[$r['d']] = (int)$r['a']; }
$chartDays = min($days, 60);
$series = $seriesA = [];
for ($i = $chartDays - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $series[] = ['label' => date('d.m', strtotime($d)), 'value' => $daily[$d] ?? 0]; $seriesA[] = ['label' => date('d.m', strtotime($d)), 'value' => $dailyA[$d] ?? 0]; }
$hours = array_fill(0, 24, 0);
foreach (qa("SELECT HOUR(e.created_at) h, COUNT(*) n $J WHERE $W GROUP BY h", $p) as $r) $hours[(int)$r['h']] = (int)$r['n'];
$hourSeries = [];
foreach (range(0, 23) as $h) $hourSeries[] = ['label' => sprintf('%02d', $h), 'value' => $hours[$h]];

$bySchool = qa("SELECT s.id, s.name, COUNT(e.id) clicks, COUNT(DISTINCT e.student_id) st,
    COUNT(DISTINCT CASE WHEN e.feature IN ($a11yIn) THEN e.student_id END) a11y_st, SUM(e.feature IN ($a11yIn)) a11y_uses,
    (SELECT COUNT(*) FROM users x WHERE x.school_id=s.id AND x.role='student' AND x.active=1) total
    FROM schools s LEFT JOIN users st ON st.school_id=s.id LEFT JOIN interaction_events e ON e.student_id=st.id AND e.created_at >= ?" . ($bookId ? ' AND e.book_id=' . $bookId : '') . '
    ' . ($schoolId ? 'WHERE s.id=' . $schoolId : '') . ' GROUP BY s.id ORDER BY clicks DESC', [$since]);

$perStudent = qa("SELECT st.id, st.full_name, st.avatar, s.name school, COUNT(*) clicks, SUM(e.feature IN ($a11yIn)) a11y_uses,
    GROUP_CONCAT(DISTINCT CASE WHEN e.feature IN ($a11yIn) THEN e.feature END) feats, COUNT(DISTINCT e.session_id) sessions, MAX(e.created_at) last
    $J LEFT JOIN schools s ON s.id=st.school_id WHERE $W GROUP BY st.id ORDER BY clicks DESC LIMIT 150", $p);

$recent = qa("SELECT e.*, st.full_name, st.avatar, b.title book, bp.page_number $J LEFT JOIN books b ON b.id=e.book_id
    LEFT JOIN book_pages bp ON bp.book_id=e.book_id AND bp.section_id=e.section_id WHERE $W" . ($feature ? ' AND e.feature = ?' : '') . ' ORDER BY e.id DESC LIMIT 150',
    $feature ? [...$p, $feature] : $p);

$schools = qa('SELECT id, name FROM schools ORDER BY name');
$books = qa('SELECT id, title FROM books ORDER BY title');
$qs = fn(array $o = []) => '?' . http_build_query(array_merge(['days' => $days, 'school' => $schoolId, 'book' => $bookId, 'feature' => $feature], $o));

$filters = '<form method="get" class="row">';
$filters .= '<select name="school" onchange="this.form.submit()" aria-label="Shkolla"><option value="0">Të gjitha shkollat</option>';
foreach ($schools as $s) $filters .= '<option value="' . $s['id'] . '"' . ($schoolId === (int)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>';
$filters .= '</select><select name="book" onchange="this.form.submit()" aria-label="Libri"><option value="0">Të gjithë librat</option>';
foreach ($books as $b) $filters .= '<option value="' . $b['id'] . '"' . ($bookId === (int)$b['id'] ? ' selected' : '') . '>' . e($b['title']) . '</option>';
$filters .= '</select><select name="days" onchange="this.form.submit()" aria-label="Periudha">';
foreach ([1 => 'Sot', 7 => '7 ditë', 30 => '30 ditë', 90 => '90 ditë', 365 => '1 vit'] as $k => $l) $filters .= '<option value="' . $k . '"' . ($days === $k ? ' selected' : '') . '>' . $l . '</option>';
$filters .= '</select><input type="hidden" name="feature" value="' . e($feature) . '"></form>';
$filters .= '<a class="btn" href="' . e($qs(['export' => 'csv'])) . '">⬇️ CSV</a>';

layout_start('Klikimet & qasshmëria', ['actions' => $filters]);
$active = (int)$tot['students'];
?>
<p class="muted">Çdo klikim i nxënësve në libra dhe çdo përdorim i veçorive të qasshmërisë së ADT Studio (lexim me zë, gjuha e shenjave, lexim i lehtë, fjalori, rrokjet, përshkrimi i imazheve, tema/madhësia…). <strong>Këto të dhëna i sheh vetëm administratori.</strong></p>
<div class="stats">
  <div class="stat"><div class="lbl">🖱️ Klikime gjithsej</div><div class="val"><?= number_format((int)$tot['clicks'], 0, ',', '.') ?></div><div class="sub"><?= $active ?> nxënës · <?= $active ? round((int)$tot['clicks'] / $active) : 0 ?> / nxënës</div></div>
  <div class="stat"><div class="lbl">♿ Përdorime qasshmërie</div><div class="val"><?= number_format((int)$a11y['uses'], 0, ',', '.') ?></div><div class="sub"><?= pct((int)$a11y['uses'], (int)$tot['clicks']) ?>% e klikimeve</div></div>
  <div class="stat"><div class="lbl">🧒 Nxënës që përdorin qasshmërinë</div><div class="val"><?= (int)$a11y['students'] ?></div><?= progress_bar(pct((int)$a11y['students'], $active)) ?><div class="sub"><?= pct((int)$a11y['students'], $active) ?>% e nxënësve aktivë</div></div>
  <div class="stat"><div class="lbl">📚 Seanca leximi</div><div class="val"><?= (int)$sess['n'] ?></div><div class="sub">mesatarisht <?= e(fmt_duration((int)$sess['avg_s'])) ?> / seancë</div></div>
  <div class="stat"><div class="lbl">📈 Pjesëmarrja</div><div class="val"><?= pct($active, $studentsTotal) ?>%</div><div class="sub"><?= $active ?> nga <?= $studentsTotal ?> nxënës</div></div>
</div>

<div class="grid-2">
  <section class="card">
    <h2>♿ Veçoritë e qasshmërisë</h2>
    <div class="hbars">
      <?php $maxA = max(1, ...array_map(fn($f) => (int)($byFeature[$f]['n'] ?? 0), a11y_features()));
      foreach (a11y_features() as $f): $r = $byFeature[$f] ?? null; ?>
        <div class="hbar"><a href="<?= e($qs(['feature' => $f])) ?>#log"><?= e(feature_label($f)) ?></a><?= progress_bar(pct((int)($r['n'] ?? 0), $maxA)) ?>
          <span class="small <?= $r ? '' : 'muted' ?>"><?= $r ? (int)$r['n'] . ' përdorime · ' . (int)$r['st'] . ' nxënës (' . pct((int)$r['st'], $active) . '%)' : 'pa përdorim' ?></span></div>
      <?php endforeach; ?>
    </div>
    <p class="small muted mt">Veçoritë që libri nuk i ka të aktivizuara në eksportin e ADT Studio (p.sh. lexim me zë pa audio) shfaqen "pa përdorim".</p>
  </section>
  <section class="card">
    <h2>🖱️ Të gjitha klikimet sipas llojit</h2>
    <div class="table-wrap"><table class="t">
      <thead><tr><th>Veçoria</th><th class="num">Klikime</th><th class="num">Nxënës</th><th>Hera e fundit</th></tr></thead>
      <tbody>
      <?php uasort($byFeature, fn($a, $b) => $b['n'] <=> $a['n']);
      foreach ($byFeature as $f => $r): ?>
        <tr><td><a href="<?= e($qs(['feature' => $f])) ?>#log"><?= e(feature_label($f)) ?></a><?= ($cat[$f][2] ?? false) ? ' <span class="badge">♿</span>' : '' ?></td>
          <td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= (int)$r['st'] ?></td><td class="small"><?= e(ago($r['last'])) ?></td></tr>
      <?php endforeach; if (!$byFeature): ?><tr><td colspan="4" class="empty">Asnjë klikim në këtë periudhë.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</div>

<div class="grid-2">
  <section class="card"><h2>📅 Klikime në ditë</h2><?= bar_chart($series, 'klikime', 'Klikime në ditë') ?></section>
  <section class="card"><h2>♿ Përdorime qasshmërie në ditë</h2><?= bar_chart($seriesA, 'përdorime', 'Përdorime të veçorive të qasshmërisë në ditë') ?></section>
</div>
<section class="card"><h2>🕘 Ora e ditës kur mësojnë nxënësit</h2><?= bar_chart($hourSeries, 'klikime', 'Klikime sipas orës') ?></section>

<section class="card">
  <h2>🏫 Sipas shkollës</h2>
  <div class="table-wrap"><table class="t">
    <thead><tr><th>Shkolla</th><th class="num">Nxënës aktivë</th><th class="num">Klikime</th><th class="num">Përdorime ♿</th><th>Nxënës që përdorin qasshmërinë</th></tr></thead>
    <tbody><?php foreach ($bySchool as $s): $pp = pct((int)$s['a11y_st'], (int)$s['st']); ?>
      <tr><td><a href="<?= e($qs(['school' => $s['id']])) ?>"><?= e($s['name']) ?></a></td><td class="num"><?= (int)$s['st'] ?>/<?= (int)$s['total'] ?></td><td class="num"><?= (int)$s['clicks'] ?></td><td class="num"><?= (int)$s['a11y_uses'] ?></td>
        <td><div class="pcell"><?= progress_bar($pp) ?><span class="small"><?= (int)$s['a11y_st'] ?> (<?= $pp ?>%)</span></div></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<section class="card">
  <div class="row-between"><h2>🧒 Sipas nxënësit</h2><input type="search" placeholder="🔎 Kërko…" data-filter="tblSt" style="max-width:240px"></div>
  <div class="table-wrap" style="max-height:520px;overflow:auto"><table class="t" id="tblSt">
    <thead><tr><th>Nxënësi</th><th>Shkolla</th><th class="num">Seanca</th><th class="num">Klikime</th><th class="num">Përdorime ♿</th><th>Veçoritë e përdorura</th><th>E fundit</th></tr></thead>
    <tbody><?php foreach ($perStudent as $s): ?>
      <tr><td><?= e($s['avatar']) ?> <a href="../teacher/student.php?id=<?= (int)$s['id'] ?>#usage"><?= e($s['full_name']) ?></a></td><td class="small"><?= e($s['school']) ?></td>
        <td class="num"><?= (int)$s['sessions'] ?></td><td class="num"><?= (int)$s['clicks'] ?></td><td class="num"><?= (int)$s['a11y_uses'] ?></td>
        <td style="font-size:1.15rem"><?php foreach (array_filter(explode(',', (string)$s['feats'])) as $f): ?><span title="<?= e($cat[$f][1] ?? $f) ?>"><?= $cat[$f][0] ?? '•' ?></span> <?php endforeach; ?><?= $s['feats'] ? '' : '<span class="muted small">—</span>' ?></td>
        <td class="small"><?= e(ago($s['last'])) ?></td></tr>
    <?php endforeach; if (!$perStudent): ?><tr><td colspan="7" class="empty">Asnjë e dhënë.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>

<section class="card" id="log">
  <div class="row-between"><h2>📜 Regjistri i klikimeve <?= $feature ? '· ' . e(feature_label($feature)) . ' <a class="small" href="' . e($qs(['feature' => ''])) . '#log">(hiq filtrin)</a>' : '' ?></h2>
    <span class="small muted">150 të fundit · CSV për të gjitha</span></div>
  <div class="table-wrap" style="max-height:520px;overflow:auto"><table class="t">
    <thead><tr><th>Koha</th><th>Nxënësi</th><th>Libri · faqja</th><th>Veçoria</th><th>Veprimi</th><th>Elementi</th><th>Vlera</th></tr></thead>
    <tbody><?php foreach ($recent as $r): ?>
      <tr><td class="small"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['avatar']) ?> <?= e($r['full_name']) ?></td>
        <td class="small"><?= $r['book'] ? e(mb_strimwidth($r['book'], 0, 28, '…')) . ($r['page_number'] ? ' · f. ' . (int)$r['page_number'] : '') : '<span class="muted">portali</span>' ?></td>
        <td class="small"><?= e(feature_label($r['feature'])) ?></td><td class="small"><?= e($r['action']) ?></td>
        <td class="small"><?= e(mb_strimwidth((string)$r['label'], 0, 50, '…')) ?></td><td class="small"><?= e($r['value']) ?></td></tr>
    <?php endforeach; if (!$recent): ?><tr><td colspan="7" class="empty">Asnjë klikim.</td></tr><?php endif; ?></tbody>
  </table></div>
</section>
<?php layout_end();
