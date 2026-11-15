<?php
// Vlerësimet e mia — nxënësi i sheh pasi mësuesja t'i ketë verifikuar (listë e palosshme, interaktive)
require __DIR__ . '/../inc/bootstrap.php';
$u = require_role('student');
$sid = (int)$u['id'];
$rows = []; $texts = []; $waiting = 0;
try {
    $rows = qa('SELECT q.*, b.title book, bp.page_number, bp.title ptitle, bp.position FROM quiz_attempts q
        JOIN books b ON b.id=q.book_id LEFT JOIN book_pages bp ON bp.book_id=q.book_id AND bp.section_id=q.section_id
        WHERE q.student_id=? AND q.verified_at IS NOT NULL
          AND q.id = (SELECT MAX(q2.id) FROM quiz_attempts q2 WHERE q2.student_id=q.student_id AND q2.book_id=q.book_id AND q2.section_id=q.section_id)
        ORDER BY q.verified_at DESC LIMIT 300', [$sid]);
    $waiting = (int)qv('SELECT COUNT(*) FROM quiz_attempts q WHERE q.student_id=? AND ' . pending_verify_sql(), [$sid]);
} catch (Throwable) {}
try {
    $texts = qa("SELECT w.*, b.title book, bp.page_number, bp.title ptitle FROM student_work w JOIN books b ON b.id=w.book_id
        LEFT JOIN book_pages bp ON bp.book_id=w.book_id AND bp.section_id=w.section_id
        WHERE w.student_id=? AND w.verified_at IS NOT NULL ORDER BY w.verified_at DESC LIMIT 300", [$sid]);
} catch (Throwable) {}

// Një listë e vetme: ushtrimet + tekstet, sipas librit
$items = [];
foreach ($rows as $r) $items[] = ['kind' => 'ex', 'new' => !(int)($r['student_seen'] ?? 1), 'score' => $r['score_pct'] !== null ? (int)$r['score_pct'] : null, 'note' => (string)$r['teacher_note'],
    'book' => $r['book'], 'book_id' => (int)$r['book_id'], 'section' => (string)$r['section_id'], 'page' => $r['page_number'], 'title' => (string)($r['ptitle'] ?: 'Ushtrim'),
    'when' => $r['verified_at'], 'answers' => $r['answers_json'], 'type' => $r['activity_type'], 'text' => null];
foreach ($texts as $t) $items[] = ['kind' => 'text', 'new' => !(int)$t['student_seen'], 'score' => $t['score'] !== null ? (int)$t['score'] : null, 'note' => (string)$t['teacher_note'],
    'book' => $t['book'], 'book_id' => (int)$t['book_id'], 'section' => (string)$t['section_id'], 'page' => $t['page_number'],
    'title' => $t['item_key'] === 'notepad-content' ? 'Shënimet e mia' : (preg_match('/^(Fusha |pg\d|txt:)/', $lbl = trim(preg_replace('/^(✍️|🗒️)\s*/u', '', work_item_label((int)$t['book_id'], $t['section_id'], $t['item_key'])))) ? 'Teksti im' : $lbl),
    'when' => $t['verified_at'], 'answers' => null, 'type' => null, 'text' => (string)$t['value']];
usort($items, fn($a, $b) => [$b['new'], $b['when']] <=> [$a['new'], $a['when']]);
$newCount = count(array_filter($items, fn($i) => $i['new']));
if ($newCount) {
    try { q('UPDATE quiz_attempts SET student_seen=1 WHERE student_id=? AND verified_at IS NOT NULL', [$sid]); q('UPDATE student_work SET student_seen=1 WHERE student_id=? AND verified_at IS NOT NULL', [$sid]); } catch (Throwable) {}
}

$face = function (?int $s): array {   // [emoji, fjalë, ngjyra, yje]
    if ($s === null) return ['✅', 'Mësuesja e lexoi', '#14804a', 0];
    if ($s >= 90) return ['🌟', 'Shkëlqyeshëm!', '#14804a', 3];
    if ($s >= 70) return ['😀', 'Shumë mirë!', '#0a7cc1', 3];
    if ($s >= 50) return ['🙂', 'Mirë!', '#b8860b', 2];
    if ($s >= 25) return ['🤔', 'Provo edhe një herë', '#d9742b', 1];
    return ['💪', 'Mos u dorëzo!', '#c0322b', 1];
};
$scores = array_filter(array_map(fn($i) => $i['score'], $items), fn($v) => $v !== null);
$avg = $scores ? (int)round(array_sum($scores) / count($scores)) : null;
$stars = array_sum(array_map(fn($i) => $face($i['score'])[3], $items));
$perfect = count(array_filter($scores, fn($v) => $v >= 90));
$withNote = count(array_filter($items, fn($i) => $i['note'] !== ''));
$by = [];
foreach ($items as $i) $by[$i['book']][] = $i;

layout_start('Vlerësimet e mia');
?>
<style>
.gr-hero{display:flex;gap:18px;align-items:center;flex-wrap:wrap;background:linear-gradient(120deg,#dff3ff,#e9fbe9 60%,#fff6d8);border-radius:26px;padding:22px 26px;margin-bottom:16px;position:relative;overflow:hidden}
.gr-hero h1{margin:0;font-size:1.8rem}.gr-hero p{margin:4px 0 0}
.gr-star{font-size:3.6rem;line-height:1;animation:grBob 2.4s ease-in-out infinite}
@keyframes grBob{0%,100%{transform:translateY(0) rotate(-6deg)}50%{transform:translateY(-6px) rotate(8deg)}}
.gr-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px}
.gr-tile{background:#fff;border:3px solid #ffe2b8;border-radius:20px;padding:12px 14px;text-align:center}
.gr-tile b{display:block;font-size:1.9rem;line-height:1.1}.gr-tile>span{font-size:.9rem}
.gr-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;align-items:center}
.gr-chip{border:3px solid #ffe2b8;background:#fff;border-radius:99px;padding:8px 16px;font:inherit;font-weight:700;cursor:pointer;min-height:44px}
.gr-chip[aria-pressed="true"]{background:#ff8a3d;border-color:#ff8a3d;color:#fff}
.gr-chip:focus-visible,.gr-item>summary:focus-visible,.gr-book>summary:focus-visible{outline:4px solid #2f5bea;outline-offset:2px}
.gr-book{background:#fff;border:3px solid #ffe2b8;border-radius:24px;margin-bottom:16px;overflow:hidden}
.gr-book>summary{list-style:none;cursor:pointer;display:flex;gap:12px;align-items:center;padding:16px 20px;font-size:1.2rem;font-weight:800;background:#fff8ea}
.gr-book>summary::-webkit-details-marker,.gr-item>summary::-webkit-details-marker{display:none}
.gr-arrow{display:inline-block;transition:transform .2s;font-size:1rem;color:#b07b2c}details[open]>summary .gr-arrow{transform:rotate(90deg)}
.gr-list{padding:10px 12px 14px;display:grid;gap:10px}
.gr-item{border:2px solid #eee3cf;border-radius:18px;overflow:hidden;background:#fff;transition:box-shadow .15s,transform .15s}
.gr-item:hover{box-shadow:0 6px 18px rgba(0,0,0,.08);transform:translateY(-1px)}
.gr-item.is-new{border-color:#f0a020;box-shadow:0 0 0 3px rgba(240,160,32,.25)}
.gr-item>summary{list-style:none;cursor:pointer;display:grid;grid-template-columns:56px minmax(0,1fr) auto 24px;gap:12px;align-items:center;padding:12px 14px;min-height:64px}
.gr-face{font-size:2.3rem;line-height:1;text-align:center;transition:transform .2s}.gr-item:hover .gr-face,.gr-item[open] .gr-face{transform:scale(1.15) rotate(-6deg)}
.gr-ttl{font-weight:800;font-size:1.05rem}.gr-sub{font-size:.92rem;color:#5a6377}
.gr-yje{font-size:1.15rem;letter-spacing:2px;white-space:nowrap}.gr-yje .off{opacity:.25;filter:grayscale(1)}
.gr-new{display:inline-block;background:#ff5a4e;color:#fff;border-radius:99px;font-size:.75rem;padding:1px 9px;font-weight:800;margin-left:6px;animation:grPulse 1.4s ease-in-out infinite}
@keyframes grPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
.gr-body{padding:4px 16px 16px 82px;animation:grOpen .25s ease}
@keyframes grOpen{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.gr-bar{height:16px;border-radius:99px;background:#eef1f6;overflow:hidden;margin:6px 0 4px;max-width:420px}
.gr-bar>i{display:block;height:100%;width:0;border-radius:99px;transition:width .9s cubic-bezier(.2,.8,.2,1)}
.gr-bubble{display:flex;gap:10px;align-items:flex-start;margin:10px 0}
.gr-bubble .who{font-size:2rem;line-height:1}
.gr-bubble .say{background:#fff6dc;border:2px solid #ffd98a;border-radius:4px 18px 18px 18px;padding:10px 14px;font-size:1.05rem;max-width:560px}
.gr-mine{background:#f6f8fb;border-radius:14px;padding:10px 14px;margin-top:8px}
.gr-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.gr-empty{text-align:center;padding:30px}
.gr-sky{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:50}
.gr-conf{position:absolute;top:-30px;font-size:1.6rem;pointer-events:none;z-index:50;animation:grFall linear forwards}
@keyframes grFall{to{transform:translateY(105vh) rotate(540deg);opacity:.9}}
@media(max-width:640px){.gr-item>summary{grid-template-columns:44px minmax(0,1fr) 20px}.gr-item>summary .gr-right{grid-column:2}.gr-body{padding-left:16px}}
@media(prefers-reduced-motion:reduce){.gr-star,.gr-new,.gr-conf{animation:none}.gr-bar>i{transition:none}}
body.hc .gr-hero,body.hc .gr-tile,body.hc .gr-book,body.hc .gr-book>summary,body.hc .gr-item,body.hc .gr-chip,body.hc .gr-bubble .say,body.hc .gr-mine{background:#000!important;color:#fff!important;border-color:#fff!important}
</style>

<section class="gr-hero">
  <span class="gr-star" aria-hidden="true">⭐</span>
  <div style="flex:1;min-width:220px"><h1>Vlerësimet e mia</h1>
    <p><?php if ($items): ?>Mësuesja ka kontrolluar <strong><?= count($items) ?></strong> punë të tua. <?= $newCount ? '🎉 <strong>' . $newCount . ' janë të reja!</strong>' : 'Shtyp mbi secilën për ta hapur.' ?><?php else: ?>Këtu do t'i shohësh vlerësimet pasi mësuesja t'i kontrollojë punët e tua.<?php endif; ?>
      <?php if ($waiting): ?><br><span class="small">⏳ Edhe <?= $waiting ?> ushtrime presin kontrollin e mësueses.</span><?php endif; ?></p></div>
</section>

<?php if ($items): ?>
<div class="gr-tiles">
  <div class="gr-tile"><b>⭐ <span data-count="<?= $stars ?>">0</span></b><span>yje të fituara</span></div>
  <div class="gr-tile"><b>🌟 <span data-count="<?= $perfect ?>">0</span></b><span>punë shkëlqyeshëm</span></div>
  <div class="gr-tile"><b>💬 <span data-count="<?= $withNote ?>">0</span></b><span>komente nga mësuesja</span></div>
  <?php if ($avg !== null): ?><div class="gr-tile"><b><?= $face($avg)[0] ?> <span data-count="<?= $avg ?>">0</span>%</b><span>mesatarja ime</span></div><?php endif; ?>
</div>

<div class="gr-chips" role="group" aria-label="Filtro vlerësimet">
  <button type="button" class="gr-chip" data-filter="all" aria-pressed="true">📋 Të gjitha</button>
  <?php if ($newCount): ?><button type="button" class="gr-chip" data-filter="new" aria-pressed="false">🆕 Të reja (<?= $newCount ?>)</button><?php endif; ?>
  <button type="button" class="gr-chip" data-filter="top" aria-pressed="false">🌟 Shkëlqyeshëm</button>
  <button type="button" class="gr-chip" data-filter="retry" aria-pressed="false">💪 Për t'u përmirësuar</button>
  <button type="button" class="gr-chip" data-filter="note" aria-pressed="false">💬 Me koment</button>
  <button type="button" class="gr-chip" data-toggle-all style="margin-left:auto">↕️ Hap / mbyll të gjitha</button>
</div>

<?php $bi = 0; foreach ($by as $book => $list): $bi++;
    $bs = array_filter(array_map(fn($i) => $i['score'], $list), fn($v) => $v !== null); $bavg = $bs ? (int)round(array_sum($bs) / count($bs)) : null;
    $bnew = count(array_filter($list, fn($i) => $i['new'])); ?>
  <details class="gr-book" <?= $bi === 1 || $bnew ? 'open' : '' ?>>
    <summary><span class="gr-arrow" aria-hidden="true">▶</span><span aria-hidden="true">📗</span><span style="flex:1"><?= e($book) ?><?= $bnew ? '<span class="gr-new">' . $bnew . ' të reja</span>' : '' ?></span>
      <span class="small" style="font-weight:600"><?= count($list) ?> vlerësime<?= $bavg !== null ? ' · ' . $face($bavg)[0] . ' ' . $bavg . '%' : '' ?></span></summary>
    <div class="gr-list">
    <?php foreach ($list as $i): [$ico, $word, $col, $st] = $face($i['score']);
        $cat = ($i['new'] ? 'new ' : '') . ($i['score'] !== null && $i['score'] >= 90 ? 'top ' : '') . ($i['score'] !== null && $i['score'] < 70 ? 'retry ' : '') . ($i['note'] !== '' ? 'note ' : ''); ?>
      <details class="gr-item <?= $i['new'] ? 'is-new' : '' ?>" data-cat="<?= e(trim($cat)) ?>" data-score="<?= $i['score'] ?? '' ?>">
        <summary>
          <span class="gr-face" aria-hidden="true"><?= $ico ?></span>
          <span><span class="gr-ttl"><?= $i['kind'] === 'text' ? '📝 ' : '' ?><?= $i['page'] ? 'Faqja ' . (int)$i['page'] . ' · ' : '' ?><?= e(mb_strimwidth($i['title'], 0, 70, '…')) ?></span><?= $i['new'] ? '<span class="gr-new">E re</span>' : '' ?>
            <br><span class="gr-sub" style="color:<?= $col ?>;font-weight:700"><?= e($word) ?></span><?= $i['note'] !== '' ? ' <span class="gr-sub">· 💬 mësuesja të ka shkruar</span>' : '' ?></span>
          <span class="gr-right" style="text-align:right"><?php if ($i['score'] !== null): ?><span class="gr-yje" aria-label="<?= $st ?> nga 3 yje"><?php for ($k = 1; $k <= 3; $k++): ?><span class="<?= $k <= $st ? '' : 'off' ?>">⭐</span><?php endfor; ?></span><br><strong style="font-size:1.25rem;color:<?= $col ?>"><?= $i['score'] ?>%</strong><?php else: ?><strong style="font-size:1.6rem">✔</strong><?php endif; ?></span>
          <span class="gr-arrow" aria-hidden="true">▶</span>
        </summary>
        <div class="gr-body">
          <?php if ($i['score'] !== null): ?><div class="gr-bar" role="img" aria-label="Rezultati <?= $i['score'] ?> për qind"><i data-w="<?= $i['score'] ?>" style="background:<?= $col ?>"></i></div><?php endif; ?>
          <?php if ($i['note'] !== ''): ?>
            <div class="gr-bubble"><span class="who" aria-hidden="true">👩‍🏫</span><div class="say"><span class="small muted">Mësuesja thotë:</span><br><?= nl2br(e($i['note'])) ?></div></div>
          <?php else: ?><p class="gr-sub" style="margin:8px 0">👩‍🏫 Mësuesja e ka kontrolluar këtë punë më <?= e(fmt_dt($i['when'], false)) ?>.</p><?php endif; ?>
          <?php if ($i['kind'] === 'text'): ?>
            <div class="gr-mine"><span class="small muted">Çka kam shkruar:</span><br>“<?= nl2br(e(mb_strimwidth((string)$i['text'], 0, 1200, '…'))) ?>”</div>
          <?php else: ?>
            <details class="gr-mine"><summary style="cursor:pointer;font-weight:700">👀 Shiko përgjigjet e mia</summary><div style="margin-top:8px"><?= answers_html($i['answers'], $i['type']) ?></div></details>
          <?php endif; ?>
          <div class="gr-actions">
            <?php if ($i['section'] !== ''): ?><a class="btn btn-primary" href="player.php?book=<?= $i['book_id'] ?>&page=<?= e(urlencode($i['section'])) ?>">📖 <?= $i['score'] !== null && $i['score'] < 70 ? 'Provoje përsëri në libër' : 'Hape këtë faqe në libër' ?></a><?php endif; ?>
            <span class="gr-sub" style="align-self:center">🗓️ <?= e(fmt_dt($i['when'], false)) ?></span>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>
<div class="kid-card gr-empty" id="grNone" hidden><div style="font-size:2.6rem">🔎</div><p>Asnjë vlerësim për këtë filtër.</p></div>

<script>
(function () {
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  // numrat që rriten
  document.querySelectorAll('[data-count]').forEach(function (el) {
    var to = +el.dataset.count, t0 = null;
    if (reduce || !to) { el.textContent = to; return; }
    requestAnimationFrame(function step(t) { if (!t0) t0 = t; var p = Math.min(1, (t - t0) / 900); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); });
  });
  // shiriti mbushet kur hapet rreshti
  function fill(d) { d.querySelectorAll('.gr-bar > i').forEach(function (i) { requestAnimationFrame(function () { i.style.width = (d.open ? i.dataset.w : 0) + '%'; }); }); }
  var items = Array.prototype.slice.call(document.querySelectorAll('.gr-item'));
  items.forEach(function (d) { d.addEventListener('toggle', function () { fill(d); if (d.open && +d.dataset.score >= 90 && !d._c) { d._c = 1; confetti(8); } }); if (d.open) fill(d); });
  // filtrat
  var chips = document.querySelectorAll('.gr-chip[data-filter]'), none = document.getElementById('grNone');
  chips.forEach(function (c) { c.addEventListener('click', function () {
    chips.forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
    var f = c.dataset.filter, shown = 0;
    items.forEach(function (d) { var ok = f === 'all' || (' ' + d.dataset.cat + ' ').indexOf(' ' + f + ' ') >= 0; d.hidden = !ok; if (ok) shown++; });
    document.querySelectorAll('.gr-book').forEach(function (b) { var any = b.querySelectorAll('.gr-item:not([hidden])').length; b.hidden = !any; if (any && f !== 'all') b.open = true; });
    none.hidden = shown > 0;
  }); });
  // hap / mbyll të gjitha
  var tg = document.querySelector('[data-toggle-all]');
  if (tg) tg.addEventListener('click', function () {
    var vis = items.filter(function (d) { return !d.hidden; }), open = vis.some(function (d) { return !d.open; });
    document.querySelectorAll('.gr-book:not([hidden])').forEach(function (b) { b.open = true; });
    vis.forEach(function (d) { d.open = open; });
  });
  // konfeti
  function confetti(n) {
    if (reduce) return;
    var e = ['⭐', '🌟', '🎉', '✨', '🎈'];
    var sky = document.querySelector('.gr-sky');
    if (!sky) { sky = document.createElement('div'); sky.className = 'gr-sky'; sky.setAttribute('aria-hidden', 'true'); document.body.appendChild(sky); }
    for (var i = 0; i < n; i++) (function (i) {
      var s = document.createElement('span'); s.className = 'gr-conf'; s.textContent = e[i % e.length]; s.setAttribute('aria-hidden', 'true');
      s.style.left = (3 + Math.random() * 90) + '%'; s.style.animationDuration = (1.8 + Math.random() * 1.6) + 's'; s.style.animationDelay = (Math.random() * .5) + 's';
      sky.appendChild(s); setTimeout(function () { s.remove(); }, 4200);
    })(i);
  }
  if (<?= $newCount ? 'true' : 'false' ?>) setTimeout(function () { confetti(22); }, 350);
})();
</script>
<?php else: ?>
<div class="kid-card gr-empty"><div style="font-size:3rem">📭</div><p>Ende nuk ka vlerësime. Zgjidh ushtrimet në libër dhe shtyp <strong>Dërgo</strong> — mësuesja do t'i kontrollojë!</p><a class="btn btn-primary" href="index.php">📚 Te librat e mi</a></div>
<?php endif; ?>
<?php layout_end();
