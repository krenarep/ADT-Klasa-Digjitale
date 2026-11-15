<?php /* Dialogu i ngarkimit të librit: SCORM ose paketë HTML (ADT Studio) */ ?>
<dialog id="dlgUp"><div class="dlg-body">
  <div class="dlg-head"><h2>⬆️ Ngarko libër digjital</h2><button class="btn btn-ghost btn-sm" data-close aria-label="Mbyll">✕</button></div>
  <form method="post" enctype="multipart/form-data" class="form" id="upForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <fieldset style="border:1px solid var(--line);border-radius:10px;padding:10px">
      <legend class="small"><strong>Formati i librit</strong></legend>
      <label class="checkline"><input type="radio" name="format" value="auto" checked> <span><strong>Automatik</strong> — sistemi e njeh vetë (rekomandohet)</span></label>
      <label class="checkline"><input type="radio" name="format" value="scorm"> <span><strong>SCORM</strong> — .zip me <code>imsmanifest.xml</code> (SCORM 1.2 / 2004, eksport SCORM i ADT Studio)</span></label>
      <label class="checkline"><input type="radio" name="format" value="html"> <span><strong>Paketë HTML</strong> — .zip me <code>index.html</code> (eksport web i ADT Studio, ose GitHub → <em>Code → Download ZIP</em>)</span></label>
    </fieldset>
    <label>Skedari (.zip) *<input type="file" name="package" accept=".zip,application/zip,application/x-zip-compressed" required>
      <span class="help">Mos e hapni zip-in. Nëse librin e keni si dosje, ziponi <strong>të gjithë dosjen</strong> (me <code>index.html</code>, <code>assets/</code>, <code>content/</code>). Kufiri i serverit: <?= e(ini_get('upload_max_filesize')) ?>. Librat e mëdhenj zgjasin disa minuta.</span></label>
    <label>Titulli <span class="help">bosh = merret nga libri</span><input name="title"></label>
    <label>Klasa<select name="grade"><option value="0">—</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></label>
    <label>Përshkrimi<textarea name="description" rows="3"></textarea></label>
    <input type="hidden" name="upload_id"><input type="hidden" name="upload_name">
    <div id="upProgress" hidden style="margin:4px 0">
      <div style="height:14px;background:var(--line,#e5e7eb);border-radius:8px;overflow:hidden"><div id="upBar" style="height:100%;width:0;background:#00AEEF;transition:width .3s"></div></div>
      <p class="small" id="upInfo" style="margin:6px 0 0">Duke filluar…</p>
    </div>
    <button class="btn btn-primary" id="upBtn">Ngarko</button>
  </form>
</div></dialog>
<script>
(function () {
  var form = document.getElementById('upForm'); if (!form || form.dataset.chunked) return; form.dataset.chunked = '1';
  var CHUNK = 5 * 1024 * 1024, api = <?= json_encode(url('api/upload_chunk.php')) ?>;
  var mb = function (b) { return (b / 1048576).toFixed(1) + ' MB'; };
  function hexId(file) {
    var s = file.name + '|' + file.size + '|' + file.lastModified;
    if (window.crypto && crypto.subtle) return crypto.subtle.digest('SHA-256', new TextEncoder().encode(s)).then(function (h) {
      return Array.from(new Uint8Array(h)).slice(0, 16).map(function (x) { return x.toString(16).padStart(2, '0'); }).join('');
    });
    var r = ''; for (var i = 0; i < 32; i++) r += Math.floor(Math.random() * 16).toString(16); return Promise.resolve(r);
  }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  form.addEventListener('submit', async function (ev) {
    var input = form.querySelector('input[name=package]'); var file = input && input.files[0];
    if (!file || form.upload_id.value) return;            // pa skedar → lëre formularin normal
    ev.preventDefault();
    var btn = document.getElementById('upBtn'), box = document.getElementById('upProgress'), bar = document.getElementById('upBar'), info = document.getElementById('upInfo');
    btn.disabled = true; box.hidden = false;
    var csrf = form.querySelector('input[name=_csrf]').value, id = await hexId(file), offset = 0, t0 = Date.now(), sent0 = 0;
    try {
      var r = await fetch(api + '?id=' + id, { credentials: 'same-origin' }); var j = await r.json();
      if (j.ok && j.chunk) CHUNK = j.chunk;
      if (j.ok && j.received > 0 && j.received <= file.size) { offset = j.received; sent0 = offset; info.textContent = 'Vazhdojmë nga ' + mb(offset) + '…'; }
    } catch (e) {}
    while (offset < file.size) {
      var fd = new FormData(); fd.append('id', id); fd.append('offset', offset); fd.append('_csrf', csrf);
      fd.append('chunk', file.slice(offset, offset + CHUNK), 'chunk');
      var ok = false;
      for (var a = 1; a <= 6 && !ok; a++) {
        try {
          var res = await fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }); var d = await res.json();
          if (d.ok) { offset = d.received; ok = true; }
          else if (d.error === 'offset') { offset = d.received; ok = true; }
          else if (d.error === 'auth' || d.error === 'csrf') { info.textContent = '⚠️ Sesioni ka skaduar. Rifreskoni faqen dhe provoni përsëri (ngarkimi vazhdon aty ku mbeti).'; btn.disabled = false; return; }
          else throw new Error(d.error);
        } catch (e) { info.textContent = '⚠️ Lidhja u ndërpre — provë ' + a + '/6…'; await sleep(2000 * a); }
      }
      if (!ok) { info.textContent = '❌ Ngarkimi u ndal. Kontrolloni internetin dhe klikoni përsëri "Ngarko" — vazhdon nga ' + mb(offset) + '.'; btn.disabled = false; return; }
      var pct = Math.round(offset * 100 / file.size), secs = (Date.now() - t0) / 1000, speed = (offset - sent0) / Math.max(secs, 0.1);
      var eta = speed > 0 ? Math.round((file.size - offset) / speed) : 0;
      bar.style.width = pct + '%';
      info.textContent = '⬆️ ' + pct + '% · ' + mb(offset) + ' / ' + mb(file.size) + ' · ' + mb(speed) + '/s · edhe ~' + (eta >= 60 ? Math.round(eta / 60) + ' min' : eta + ' s');
    }
    bar.style.width = '100%';
    info.textContent = '📦 Skedari u ngarkua. Duke shpaketuar librin në server… (mund të zgjasë 1–2 minuta, mos e mbyllni faqen)';
    form.upload_id.value = id; form.upload_name.value = file.name;
    input.disabled = true; input.required = false;
    form.submit();
  });
})();
</script>
