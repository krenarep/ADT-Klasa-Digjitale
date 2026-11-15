<?php
// Certifikata e pjesëmarrjes së mësuesit në trajnim / punëtori (printim A4 ose "Ruaj si PDF")
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('teacher', 'admin');
$tid = geti('training');
$t = q1('SELECT * FROM trainings WHERE id=?', [$tid]);
if (!$t) { http_response_code(404); exit('Trajnimi nuk u gjet.'); }

// Admini ndryshon çdo tekst të certifikatës (ruhet për këtë trajnim; emri/shkolla për secilin mësues)
if ($u['role'] === 'admin' && is_post()) {
    csrf_check();
    $key = tcert_override_key($tid);
    if (post('action') === 'reset') {
        set_setting($key, ''); set_setting('tcert_t' . $tid . '_json', '');
        foreach (['sign1_name', 'sign1_role', 'sign2_name', 'sign2_role'] as $k) set_setting('tcert_t' . $tid . '_' . $k, '');
        audit('settings', 'Certifikata: ' . $t['title'], 'tekstet u rikthyen');
        json_out(['ok' => true]);
    }
    $in = json_decode((string)post('data'), true);
    if (!is_array($in)) json_out(['ok' => false], 400);
    $ov = tcert_overrides($tid);
    foreach ((array)($in['f'] ?? []) as $k => $v) if (in_array($k, TCERT_FIELDS, true)) $ov['f'][$k] = mb_substr(trim((string)$v), 0, 500);
    foreach ((array)($in['p'] ?? []) as $pid => $vals) {
        if (!qv('SELECT 1 FROM training_attendance WHERE training_id=? AND teacher_id=?', [$tid, (int)$pid])) continue;
        foreach ((array)$vals as $k => $v) if (in_array($k, TCERT_PERSON_FIELDS, true)) $ov['p'][(string)(int)$pid][$k] = mb_substr(trim((string)$v), 0, 200);
    }
    set_setting($key, json_encode($ov, JSON_UNESCAPED_UNICODE));
    audit('settings', 'Certifikata: ' . $t['title'], 'tekstet u ndryshuan');
    json_out(['ok' => true]);
}

$sql = "SELECT u.id, u.full_name, s.name school FROM training_attendance a JOIN users u ON u.id=a.teacher_id LEFT JOIN schools s ON s.id=u.school_id
        WHERE a.training_id=? AND a.status IN ('" . implode("','", TCERT_STATUSES) . "')";
if ($u['role'] === 'teacher') {
    $rows = qa($sql . ' AND u.id=?', [$tid, $u['id']]);
    if (!$rows) { http_response_code(403); exit('Certifikata jepet pasi administratori ta shënojë pjesëmarrjen tuaj në këtë trajnim.'); }
} else {
    $one = geti('teacher');
    $rows = qa($sql . ($one ? ' AND u.id=' . $one : '') . ' ORDER BY s.name, u.full_name', [$tid]);
    if ($rows) {
        q('UPDATE training_attendance SET certificate=1 WHERE training_id=? AND teacher_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')', [$tid]);
        audit('settings', 'Certifikata: ' . $t['title'], count($rows) . ' mësues');
    }
}
$logos = tcert_logos();
?><!DOCTYPE html><html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certifikata · <?= e($t['title']) ?></title><?= tcert_page_css() ?></head><body>
<div class="no-print">
  <button onclick="window.print()" style="font-size:16px;padding:10px 22px;border-radius:10px;border:0;background:#2f5bea;color:#fff;cursor:pointer">🖨️ Printo / Ruaj si PDF</button>
  <div style="font-size:13px;color:#5a6377;margin-top:6px"><?= count($rows) ?> certifikatë · në dritaren e printimit zgjidhni «Ruaj si PDF» dhe orientimin horizontal (Landscape).</div>
  <?php if ($u['role'] === 'admin' && $rows): ?>
  <div id="edbar" style="margin:12px auto 0;max-width:900px;background:#fff8e1;border:1px solid #f0c060;border-radius:12px;padding:10px 14px;font-size:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:center">
    <span>✏️ <b>Klikoni mbi çdo tekst</b> të certifikatës dhe shkruani. Tekstet e përbashkëta ndryshojnë në të gjitha certifikatat; <b>emri</b> dhe <b>shkolla</b> vetëm te ai mësues. Fushë bosh = nuk printohet.</span>
    <button type="button" id="edSave" style="padding:8px 16px;border-radius:8px;border:0;background:#14804a;color:#fff;cursor:pointer">💾 Ruaj ndryshimet</button>
    <button type="button" id="edReset" style="padding:8px 14px;border-radius:8px;border:1px solid #c9ced8;background:#fff;cursor:pointer">↺ Rikthe tekstet origjinale</button>
    <span id="edMsg" style="color:#14804a;font-weight:600"></span>
  </div>
  <?php endif; ?>
  <?php if (!$rows): ?><p style="color:#c0322b">Asnjë mësues me status «Attended» ose «Completed» në këtë trajnim.</p><?php endif; ?>
</div>
<?php $ed = $u['role'] === 'admin'; $ov = tcert_overrides($tid); foreach ($rows as $r) echo tcert_html(tcert_merged_training($t, (int)$r['id']), $r, $logos, $ed, $ov); ?>
<?php if ($u['role'] === 'admin' && $rows): ?>
<script>
(function () {
  var dirty = { f: {}, p: {} }, csrf = <?= json_encode(csrf_token()) ?>, msg = document.getElementById('edMsg');
  var url = location.pathname + location.search;
  document.querySelectorAll('[data-f]').forEach(function (el) {
    el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.blur(); } });
    el.addEventListener('paste', function (e) { e.preventDefault(); document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text')); });
    el.addEventListener('input', function () {
      var k = el.dataset.f, v = el.innerText.replace(/\n$/, '');
      el.classList.add('tc-dirty'); msg.textContent = '● Ka ndryshime të paruajtura'; msg.style.color = '#a15c07';
      if (el.dataset.p) { (dirty.p[el.dataset.p] = dirty.p[el.dataset.p] || {})[k] = v; return; }
      dirty.f[k] = v;
      document.querySelectorAll('[data-f="' + k + '"]:not([data-p])').forEach(function (o) { if (o !== el) { o.textContent = v; o.classList.add('tc-dirty'); } });
    });
  });
  function post(body) { return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body + '&_csrf=' + encodeURIComponent(csrf) }).then(function (r) { return r.json(); }); }
  document.getElementById('edSave').onclick = function () {
    post('data=' + encodeURIComponent(JSON.stringify(dirty))).then(function (r) {
      if (!r.ok) throw 0; dirty = { f: {}, p: {} };
      document.querySelectorAll('.tc-dirty').forEach(function (o) { o.classList.remove('tc-dirty'); });
      msg.style.color = '#14804a'; msg.textContent = '✔ U ruajt — vlen edhe kur mësuesi e shkarkon vetë.';
    }).catch(function () { msg.style.color = '#c0322b'; msg.textContent = '⚠️ Nuk u ruajt. Provoni përsëri.'; });
  };
  document.getElementById('edReset').onclick = function () {
    if (!confirm('Të rikthehen tekstet origjinale të trajnimit për këto certifikata?')) return;
    post('action=reset').then(function () { location.reload(); });
  };
  window.addEventListener('beforeunload', function (e) { if (Object.keys(dirty.f).length || Object.keys(dirty.p).length) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php endif; ?>
</body></html>
