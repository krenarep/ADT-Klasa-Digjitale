<?php
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];
$bookId = geti('book');
$b = q1('SELECT * FROM books WHERE id=?', [$bookId]);
if (!$b || !student_has_book($sid, $bookId)) { flash('err', 'Ky libër nuk është i disponueshëm.'); redirect('student/index.php'); }

$pages = qa('SELECT section_id, href, page_number, title, is_activity FROM book_pages WHERE book_id=? ORDER BY position', [$bookId]);
$visited = array_column(qa('SELECT section_id FROM page_views WHERE student_id=? AND book_id=?', [$sid, $bookId]), 'section_id');
$done = array_column(qa('SELECT DISTINCT section_id FROM quiz_attempts WHERE student_id=? AND book_id=?', [$sid, $bookId]), 'section_id');
$scorm = q1('SELECT lesson_location, lesson_status, score_raw, suspend_data, total_time_sec FROM scorm_data WHERE student_id=? AND book_id=?', [$sid, $bookId]) ?? [];

$start = $b['entry_href'];
$goto = get('page');
$loc = $goto ?: ($scorm['lesson_location'] ?? '');
foreach ($pages as $p) if ($loc !== '' && $p['section_id'] === $loc) $start = $p['href'];

$teacher = q1('SELECT t.id, t.full_name FROM classes c JOIN class_students cs ON cs.class_id=c.id JOIN class_books cb ON cb.class_id=c.id JOIN users t ON t.id=c.teacher_id
               WHERE cs.student_id=? AND cb.book_id=? LIMIT 1', [$sid, $bookId]);

$cfg = [
    'book' => $bookId,
    'type' => $b['package_type'],
    'api' => url('api/track.php'),
    'csrf' => csrf_token(),
    'pages' => array_map(fn($p) => ['id' => $p['section_id'], 'href' => $p['href'], 'n' => $p['page_number'], 'act' => (int)$p['is_activity']], $pages),
    'visited' => $visited,
    'done' => $done,
    'labels' => $b['package_type'] === 'adt' ? book_label_map($b) : [],
    'cmi' => [
        'cmi.core.student_id' => (string)$sid,
        'cmi.core.student_name' => $u['full_name'],
        'cmi.core.lesson_location' => $scorm['lesson_location'] ?? '',
        'cmi.core.lesson_status' => $scorm['lesson_status'] ?? 'not attempted',
        'cmi.core.score.raw' => $scorm['score_raw'] ?? '',
        'cmi.core.entry' => $scorm ? 'resume' : 'ab-initio',
        'cmi.suspend_data' => $scorm['suspend_data'] ?? '',
        'cmi.launch_data' => '',
        'cmi.core.credit' => 'credit',
        'cmi.core.lesson_mode' => 'normal',
    ],
];
$firstVisit = !$visited;
// Puna e ruajtur e këtij nxënësi në këtë libër (tekstet, shënimet) — rikthehet në pajisje para se të hapet libri.
// Kështu në tabletët e përbashkët nxënësi nuk sheh punën e një fëmije tjetër, dhe e gjen punën e vet në çdo pajisje.
$work = [];
try { foreach (qa("SELECT item_key, value FROM student_work WHERE student_id=? AND book_id=? AND item_key NOT LIKE 'txt:%'", [$sid, $bookId]) as $w) $work[$w['item_key']] = (string)$w['value']; } catch (Throwable) {}
$todaySec = (int)qv('SELECT COALESCE(SUM(seconds),0) FROM usage_daily WHERE student_id=? AND book_id=? AND day=CURDATE()', [$sid, $bookId]);
?><!DOCTYPE html>
<html lang="sq">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($b['title']) ?> · <?= e(cfg('app_name')) ?></title>
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<meta name="theme-color" content="#2f5bea">
<link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=4">
<script>window.APP_BASE = <?= json_encode(rtrim((string)cfg('base_url', ''), '/')) ?>;</script>
<script src="<?= e(url('assets/js/app.js')) ?>?v=6" defer></script>
<?= i18n_js() ?>
</head>
<body class="role-student player-page">
<div class="player">
  <div class="player-bar">
    <a class="btn btn-sm" href="index.php" aria-label="Kthehu te librat">← Librat</a>
    <div class="title" title="<?= e($b['title']) ?>">📗 <?= e($b['title']) ?></div>
    <div class="pp" aria-live="polite"><?= progress_bar(0, 'Faqet e lexuara') ?><span class="small" id="ppText"></span></div>
    <span class="timer-pill" title="Koha e mësimit sot në këtë libër">⏱️ <span id="timer">0:00</span></span>
    <span class="timer-pill" style="background:#fff6dc;color:#8a5a00" title="Ushtrime të dërguara">⭐ <span id="starCount"><?= count($done) ?></span></span>
    <button class="btn btn-sm" type="button" data-open="dlgGuide">❓ Si punohet</button>
    <button class="btn btn-sm btn-primary" type="button" id="helpBtn">🙋 Më ndihmo</button>
  </div>
  <script>(function(){try{var W=<?= json_encode((object)$work, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,re=/^pg\d+_sec\d+_/,rm=[],i,k;
    for(i=0;i<localStorage.length;i++){k=localStorage.key(i);if(k&&(re.test(k)||k==='notepad-content'))rm.push(k);}
    rm.forEach(function(k){localStorage.removeItem(k);});for(k in W)localStorage.setItem(k,W[k]);}catch(e){}})();</script>
  <iframe id="book" src="<?= e(book_url($b, $start)) ?>" title="<?= e($b['title']) ?>" allow="autoplay; fullscreen"></iframe>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="net-banner" id="netBanner" role="status">📴 Pa internet — puna jote ruhet dhe dërgohet vetë kur të kthehet interneti.</div>

<dialog id="dlgGuide"><div class="dlg-body">
  <div class="dlg-head"><h2>🧭 Si punohet me librin</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <div class="steps" style="grid-template-columns:1fr 1fr;margin:0">
    <div class="step"><div class="e">📖</div><strong>1. Lexo</strong><br><span class="small">Lexo udhëzimet në krye të faqes.</span></div>
    <div class="step"><div class="e">⚙️</div><strong>2. Ndihma</strong><br><span class="small">Poshtë në libër: <b>⚙️ Cilësimet</b> (shkronja, ngjyra) dhe <b>☰</b> përmbajtja.</span></div>
    <div class="step"><div class="e">✍️</div><strong>3. Plotëso</strong><br><span class="small">Shkruaj ose zgjidh, pastaj shtyp <b>Dërgo</b>. Merr një ⭐!</span></div>
    <div class="step"><div class="e">➡️</div><strong>4. Vazhdo</strong><br><span class="small">Shtyp <b>›</b> për faqen tjetër. Puna jote ruhet vetë.</span></div>
  </div>
  <p class="row-center mt"><button class="btn btn-primary btn-lg" data-close>Në rregull, fillojmë! 🚀</button></p>
</div></dialog>

<dialog id="dlgHelp"><div class="dlg-body">
  <div class="dlg-head"><h2>🙋 Më ndihmo</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form class="form" id="helpForm">
    <p class="kid-say" style="margin:0">Çfarë ndodhi? <?php if ($teacher): ?><span class="small muted">Mesazhi shkon te <?= e($teacher['full_name']) ?>.</span><?php endif; ?></p>
    <div class="cat-grid">
      <?php foreach (['not_working', 'dont_understand', 'content', 'other'] as $i => $k): [$ico, $lbl] = ticket_categories()[$k]; ?>
        <label class="cat-opt"><input type="radio" name="category" value="<?= $k ?>" <?= $i === 0 ? 'checked' : '' ?>><span><b aria-hidden="true"><?= $ico ?></b><?= e($lbl) ?></span></label>
      <?php endforeach; ?>
    </div>
    <label>Shkruaj pak më shumë<textarea name="message" required rows="3" placeholder="P.sh. Butoni Dërgo nuk punon…"></textarea></label>
    <p class="small muted" style="margin:0" id="helpPage"></p>
    <button class="btn btn-primary btn-lg">📨 Dërgoja mësuesit</button>
  </form>
</div></dialog>

<script>window.PLAYER = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>; window.PLAYER.firstVisit = <?= $firstVisit ? 'true' : 'false' ?>; window.PLAYER.today = <?= $todaySec ?>;</script>
<script src="<?= e(url('assets/js/player.js')) ?>?v=8"></script>
</body>
</html>
