<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$sid = geti('id');
$s = q1("SELECT s.*, sc.name school_name FROM users s LEFT JOIN schools sc ON sc.id=s.school_id WHERE s.id=? AND s.role='student'", [$sid]);
if (!$s || ($u['role'] === 'teacher' && !teacher_has_student((int)$u['id'], $sid))) {
    http_response_code(404); layout_start('Nxënësi'); echo '<div class="card empty">Nxënësi nuk u gjet.</div>'; layout_end(); exit;
}

if (is_post()) {
    csrf_check();
    if (post('action') === 'grade' && (int)qv('SELECT student_id FROM quiz_attempts WHERE id=?', [posti('attempt')]) === $sid) {
        handle_grade_post($u);
    } elseif (post('action') === 'parent_link' && setting('parent_links') === '1') {
        $tok = post('mode') === 'revoke' ? null : bin2hex(random_bytes(16));
        q('UPDATE users SET parent_token=? WHERE id=?', [$tok, $sid]);
        audit('parent.link', '#' . $sid, $tok ? 'U krijua link i ri' : 'Linku u çaktivizua');
        flash('ok', $tok ? 'Linku i ri për prindin u krijua (linku i vjetër nuk funksionon më).' : 'Linku i prindit u çaktivizua.');
        redirect('teacher/student.php?id=' . $sid . '#parent');
    }
    redirect('teacher/student.php?id=' . $sid . '&book=' . geti('book') . '#attempts');
}

$classes = qa('SELECT c.name, t.full_name teacher FROM classes c JOIN class_students cs ON cs.class_id=c.id JOIN users t ON t.id=c.teacher_id WHERE cs.student_id=?', [$sid]);
$books = qa('SELECT DISTINCT b.* FROM books b WHERE b.id IN (SELECT cb.book_id FROM class_books cb JOIN class_students cs ON cs.class_id=cb.class_id WHERE cs.student_id=?)
            OR b.id IN (SELECT book_id FROM page_views WHERE student_id=?) ORDER BY b.title', [$sid, $sid]);
$bookId = geti('book');
if ($books && !in_array($bookId, array_map('intval', array_column($books, 'id')), true)) $bookId = (int)$books[0]['id'];
$book = null;
foreach ($books as $b) if ((int)$b['id'] === $bookId) $book = $b;

$totalSec = (int)qv('SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=?', [$sid]);
$daysActive = (int)qv('SELECT COUNT(DISTINCT day) FROM usage_daily WHERE student_id=?', [$sid]);

layout_start($s['full_name'], ['nav' => $u['role'] === 'admin' ? 'admin/students.php' : 'teacher/progress.php']);
?>
<section class="card">
  <div class="row" style="gap:16px">
    <span style="font-size:3rem" aria-hidden="true"><?= e($s['avatar']) ?></span>
    <div style="flex:1">
      <h2 style="margin:0"><?= e($s['full_name']) ?> <?= student_badges($s) ?><?= $s['active'] ? '' : '<span class="badge badge-off">Joaktiv</span>' ?></h2>
      <div class="muted small"><code><?= e($s['username']) ?></code> · <?= e($s['school_name']) ?> · <?= e(implode(', ', array_map(fn($c) => $c['name'] . ' (' . $c['teacher'] . ')', $classes)) ?: 'pa klasë') ?></div>
    </div>
    <dl class="kv" style="grid-template-columns:auto auto">
      <dt>Hyrja e fundit</dt><dd><?= e(ago($s['last_login'])) ?></dd>
      <dt>Koha gjithsej</dt><dd><?= e(fmt_duration($totalSec)) ?></dd>
      <dt>Ditë aktive</dt><dd><?= $daysActive ?></dd>
    </dl>
  </div>
</section>

<section class="card" id="parent">
  <div class="row-between"><h2 style="margin:0">👪 Prindërit</h2>
    <div class="row">
      <a class="btn btn-sm" target="_blank" href="parent_report.php?student=<?= $sid ?>&month=<?= date('Y-m') ?>">📄 Raporti mujor</a>
      <?php if (setting('parent_links') === '1'): ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="parent_link"><button class="btn btn-sm"><?= $s['parent_token'] ? '🔄 Link i ri' : '🔗 Krijo linkun për prindin' ?></button></form>
      <?php if ($s['parent_token']): ?><form method="post" class="inline" data-confirm="Ta çaktivizoj linkun e prindit?"><?= csrf_field() ?><input type="hidden" name="action" value="parent_link"><input type="hidden" name="mode" value="revoke"><button class="btn btn-sm btn-danger">⛔</button></form><?php endif; ?>
      <?php endif; ?>
    </div></div>
  <?php if ($s['parent_token'] && setting('parent_links') === '1'): $pl = parent_link($s); ?>
    <p class="small" style="margin:10px 0 0">Linku privat (pa fjalëkalim) ku prindi sheh raportin mujor: <input readonly value="<?= e($pl) ?>" onclick="this.select()" style="max-width:520px;font-size:.85rem;min-height:32px;padding:4px 8px"></p>
  <?php endif; ?>
  <?php if (!empty($s['sen_consent_at'])): ?><p class="small muted" style="margin:8px 0 0">♿ Pëlqimi për të dhënat e NV: <?= e($s['sen_consent_by']) ?> · <?= e(fmt_dt($s['sen_consent_at'], false)) ?></p><?php endif; ?>
</section>

<?php if (!$books): ?><div class="card empty">Nxënësi nuk ka libra të caktuar.</div><?php layout_end(); exit; endif; ?>

<div class="tabs">
  <?php foreach ($books as $b): ?><a href="?id=<?= $sid ?>&book=<?= (int)$b['id'] ?>" class="<?= (int)$b['id'] === $bookId ? 'active' : '' ?>">📚 <?= e($b['title']) ?></a><?php endforeach; ?>
</div>

<?php
$p = progress_for([$sid], $bookId)[$sid];
$daily = [];
foreach (qa('SELECT day, seconds FROM usage_daily WHERE student_id=? AND book_id=? AND day >= ?', [$sid, $bookId, date('Y-m-d', strtotime('-29 day'))]) as $r) $daily[$r['day']] = (int)$r['seconds'];
$pages = qa('SELECT bp.*, v.seconds, v.visits, v.last_seen,
   (SELECT COUNT(*) FROM quiz_attempts q WHERE q.student_id=? AND q.book_id=bp.book_id AND q.section_id=bp.section_id) att,
   (SELECT MAX(score_pct) FROM quiz_attempts q WHERE q.student_id=? AND q.book_id=bp.book_id AND q.section_id=bp.section_id) best
  FROM book_pages bp LEFT JOIN page_views v ON v.book_id=bp.book_id AND v.section_id=bp.section_id AND v.student_id=?
  WHERE bp.book_id=? ORDER BY bp.position', [$sid, $sid, $sid, $bookId]);
$attempts = qa('SELECT q.*, bp.title, bp.page_number FROM quiz_attempts q LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
  WHERE q.student_id=? AND q.book_id=? ORDER BY q.created_at DESC LIMIT 300', [$sid, $bookId]);
$scorm = q1('SELECT * FROM scorm_data WHERE student_id=? AND book_id=?', [$sid, $bookId]);
?>
<div class="stats">
  <div class="stat"><div class="lbl">📈 Progresi</div><div class="val"><?= $p['progress'] ?>%</div><?= progress_bar($p['progress']) ?></div>
  <div class="stat"><div class="lbl">📖 Faqe të lexuara</div><div class="val"><?= $p['pages'] ?>/<?= $p['page_total'] ?></div></div>
  <div class="stat"><div class="lbl">📝 Ushtrime të dërguara</div><div class="val"><?= $p['acts_done'] ?>/<?= $p['act_total'] ?></div><div class="sub"><?= $p['attempts'] ?> tentativa gjithsej</div></div>
  <div class="stat"><div class="lbl">🎯 Saktësia</div><div class="val"><?= $p['avg_score'] !== null ? $p['avg_score'] . '%' : '—' ?></div></div>
  <div class="stat"><div class="lbl">⏱️ Koha në libër</div><div class="val"><?= e(fmt_duration($p['seconds'])) ?></div><div class="sub">aktiv <?= e(ago($p['last_seen'])) ?></div></div>
  <?php if ($scorm): ?><div class="stat"><div class="lbl">📦 SCORM</div><div class="val" style="font-size:1.1rem"><?= e($scorm['lesson_status'] ?: '—') ?></div><div class="sub">faqja e fundit: <?= e($scorm['lesson_location'] ?: '—') ?></div></div><?php endif; ?>
</div>

<?php $sessions = qa('SELECT * FROM learning_sessions WHERE student_id=? AND book_id=? ORDER BY id DESC LIMIT 40', [$sid, $bookId]); ?>
<div class="grid-2" style="grid-template-columns:1.4fr 1fr">
  <section class="card"><h2>⏱️ Minuta në ditë (30 ditët e fundit)</h2><?= bar_chart(daily_series($daily, 30), 'min', 'Minutat në ditë') ?></section>
  <section class="card">
    <h2>🔁 Sa shpesh e përdor (<?= $p['sessions'] ?> seanca · <?= $p['days'] ?> ditë)</h2>
    <div class="table-wrap" style="max-height:250px;overflow:auto"><table class="t">
      <thead><tr><th>Seanca</th><th class="num">Kohëzgjatja</th><th class="num">Faqe</th><th>Pajisja</th></tr></thead>
      <tbody><?php foreach ($sessions as $ss): ?>
        <tr><td class="small"><?= e(fmt_dt($ss['started_at'])) ?></td><td class="num small"><?= e(fmt_duration((int)$ss['seconds'])) ?></td><td class="num small"><?= (int)$ss['pages'] ?></td><td class="small"><?= e($ss['device'] ?? '—') ?></td></tr>
      <?php endforeach; if (!$sessions): ?><tr><td colspan="4" class="empty">Asnjë seancë ende.</td></tr><?php endif; ?></tbody>
    </table></div>
  </section>
</div>

<?php if ($u['role'] === 'admin'):
    $cat = feature_catalog();
    $feat = qa('SELECT feature, COUNT(*) n, MAX(created_at) last FROM interaction_events WHERE student_id=? GROUP BY feature ORDER BY n DESC', [$sid]);
    $events = qa('SELECT e.*, b.title book, bp.page_number FROM interaction_events e LEFT JOIN books b ON b.id=e.book_id
                  LEFT JOIN book_pages bp ON bp.book_id=e.book_id AND bp.section_id=e.section_id WHERE e.student_id=? ORDER BY e.id DESC LIMIT 200', [$sid]);
    $totalClicks = array_sum(array_column($feat, 'n')); ?>
<section class="card" id="usage" style="border-color:#c9d6ff">
  <div class="row-between"><h2>🖱️ Klikimet & qasshmëria <span class="badge">vetëm admin</span></h2><span class="muted small"><?= $totalClicks ?> klikime gjithsej (të gjithë librat)</span></div>
  <div class="grid-2" style="grid-template-columns:1fr 1.6fr">
    <div class="hbars">
      <?php $mx = max(1, ...array_map(fn($f) => (int)$f['n'], $feat ?: [['n' => 1]]));
      foreach ($feat as $f): ?>
        <div class="hbar"><span><?= e(feature_label($f['feature'])) ?><?= ($cat[$f['feature']][2] ?? false) ? ' ♿' : '' ?></span><?= progress_bar(pct((int)$f['n'], $mx)) ?><span class="small muted"><?= (int)$f['n'] ?></span></div>
      <?php endforeach; if (!$feat): ?><p class="muted">Asnjë klikim i regjistruar.</p><?php endif; ?>
    </div>
    <div class="table-wrap" style="max-height:420px;overflow:auto"><table class="t">
      <thead><tr><th>Koha</th><th>Libri · faqja</th><th>Veçoria</th><th>Elementi</th><th>Vlera</th></tr></thead>
      <tbody><?php foreach ($events as $ev): ?>
        <tr><td class="small"><?= e(fmt_dt($ev['created_at'])) ?></td>
          <td class="small"><?= $ev['book'] ? e(mb_strimwidth($ev['book'], 0, 22, '…')) . ($ev['page_number'] ? ' · f. ' . (int)$ev['page_number'] : '') : 'portali' ?></td>
          <td class="small"><?= e(feature_label($ev['feature'])) ?></td><td class="small"><?= e(mb_strimwidth((string)$ev['label'], 0, 40, '…')) ?></td><td class="small"><?= e($ev['value']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
</section>
<?php endif; ?>

<div class="grid-2" style="grid-template-columns:1fr 1.3fr">
  <section class="card">
    <h2>📖 Faqet</h2>
    <div class="table-wrap" style="max-height:620px;overflow:auto"><table class="t">
      <thead><tr><th>Faqja</th><th>Titulli</th><th class="num">Koha</th><th class="num">Vizita</th><th class="num">Ushtrimi</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $pg): ?>
        <tr style="<?= $pg['visits'] ? '' : 'opacity:.55' ?>">
          <td><?= $pg['page_number'] ? (int)$pg['page_number'] : '·' ?></td>
          <td class="small"><?= $pg['visits'] ? '✅' : '⬜' ?> <?= e(mb_strimwidth((string)$pg['title'], 0, 48, '…')) ?></td>
          <td class="num small"><?= $pg['seconds'] ? e(fmt_duration((int)$pg['seconds'])) : '—' ?></td>
          <td class="num small"><?= (int)$pg['visits'] ?: '—' ?></td>
          <td class="num small"><?php if ($pg['is_activity']): ?><?= $pg['att'] ? (int)$pg['att'] . '× ' . score_html($pg['best'] !== null ? (int)$pg['best'] : null) : '<span class="muted">pa dërguar</span>' ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <section class="card" id="attempts">
    <h2>📝 Tentativat e ushtrimeve</h2>
    <?php if (!$attempts): ?><p class="muted">Asnjë ushtrim i dërguar ende.</p><?php endif; ?>
    <div style="display:flex;flex-direction:column;gap:10px;max-height:620px;overflow:auto">
    <?php foreach ($attempts as $a): $ans = json_decode((string)$a['answers_json'], true) ?: []; ?>
      <details class="card" style="margin:0;padding:12px">
        <summary class="row-between" style="cursor:pointer">
          <span><strong><?= $a['page_number'] ? 'f. ' . (int)$a['page_number'] . ' · ' : '' ?><?= e(mb_strimwidth((string)($a['title'] ?? $a['section_id']), 0, 50, '…')) ?></strong>
            <span class="small muted"> · <?= e(section_type_label($a['activity_type'])) ?> · <?= e(fmt_dt($a['created_at'])) ?></span></span>
          <span><?= $a['score_pct'] !== null ? score_html((int)$a['score_pct']) . ' <span class="small muted">(' . (int)$a['correct_items'] . '/' . (int)$a['total_items'] . ')</span>' : '<span class="badge badge-in_progress">Përgjigje e hapur · pa vlerësim</span>' ?><?= $a['graded_by'] ? ' <span class="badge">✍️ mësuesi</span>' : '' ?><?= !empty($a['verified_at']) ? ' <span class="badge" title="Verifikuar nga mësuesi">✔</span>' : ' <span class="small muted" title="Pret verifikimin">⏳</span>' ?></span>
        </summary>
        <div class="mt small">
          <?= attempt_history_html($sid, (int)$a['book_id'], (string)$a['section_id']) ?>
          <?php if (!empty($ans['items'])): ?>
            <table class="t"><tbody>
            <?php foreach ($ans['items'] as $it): ?>
              <tr><td style="width:30px"><?= ['correct' => '✅', 'wrong' => '❌', 'empty' => '⬜'][$it['state'] ?? ''] ?? '✍️' ?></td>
                <td><?= e($it['label'] ?? $it['id'] ?? '') ?></td><td><strong><?= e(is_scalar($it['value'] ?? '') ? (string)($it['value'] ?? '') : json_encode($it['value'], JSON_UNESCAPED_UNICODE)) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
          <?php if ($a['empty_items']): ?><p class="muted"><?= (int)$a['empty_items'] ?> fusha bosh.</p><?php endif; ?>
          <?php if ($a['graded_by']): ?><p class="small">✍️ Vlerësuar nga mësuesi më <?= e(fmt_dt($a['graded_at'])) ?></p><?php endif; ?>
          <?= grade_form_html($a) ?>
        </div>
      </details>
    <?php endforeach; ?>
    </div>
  </section>
</div>
<?php layout_end();
