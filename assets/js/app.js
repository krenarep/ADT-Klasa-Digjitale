/* Klasa Digjitale — JS i përbashkët */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const store = {
    get(k, d) { try { const v = localStorage.getItem('kd_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set(k, v) { try { localStorage.setItem('kd_' + k, JSON.stringify(v)); } catch (e) { /* ignore */ } }
  };
  window.KD = { store };

  // Sidebar në celular
  $$('[data-toggle-sidebar]').forEach(b => b.addEventListener('click', () => $('#sidebar')?.classList.toggle('open')));
  document.addEventListener('click', e => {
    const sb = $('#sidebar');
    if (sb && sb.classList.contains('open') && !sb.contains(e.target) && !e.target.closest('[data-toggle-sidebar]')) sb.classList.remove('open');
  });

  // Shfaq fjalëkalimin
  $$('[data-showpw]').forEach(b => b.addEventListener('click', () => {
    const i = document.getElementById(b.dataset.showpw);
    if (i) i.type = i.type === 'password' ? 'text' : 'password';
  }));

  // Konfirmim para veprimeve
  document.addEventListener('submit', e => {
    const f = e.target;
    if (f.dataset.confirm && !window.confirm(window.__t ? window.__t(f.dataset.confirm) : f.dataset.confirm)) e.preventDefault();
  });

  // Dialogët
  $$('[data-open]').forEach(b => b.addEventListener('click', () => {
    const d = document.getElementById(b.dataset.open);
    if (!d) return;
    // mbush fushat nga data-fill-*
    Object.keys(b.dataset).filter(k => k.startsWith('fill')).forEach(k => {
      const name = k.slice(4).replace(/^./, c => c.toLowerCase()).replace(/[A-Z]/g, c => '_' + c.toLowerCase());
      const el = d.querySelector('[name="' + name + '"]');
      if (!el) return;
      if (el.type === 'checkbox') el.checked = b.dataset[k] === '1'; else el.value = b.dataset[k];
    });
    d.showModal();
  }));
  $$('dialog [data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));

  // Filtrim i shpejtë i tabelave
  $$('[data-filter]').forEach(inp => inp.addEventListener('input', () => {
    const t = document.getElementById(inp.dataset.filter);
    const v = inp.value.toLowerCase().trim();
    $$('tbody tr', t).forEach(tr => { tr.hidden = v && !tr.textContent.toLowerCase().includes(v); });
  }));

  // Hyrja me fotografi
  const picForm = $('#picForm');
  if (picForm) {
    let seq = [];
    const dots = $$('#seqDots span');
    const render = () => dots.forEach((d, i) => {
      const b = seq[i] !== undefined ? $('.pic-btn[data-pic="' + seq[i] + '"] span', picForm) : null;
      d.textContent = b ? b.textContent : '';
      d.classList.toggle('filled', !!b);
    });
    // Prekjet e dyfishta në tablet (dy "klikime" nga një prekje) regjistroheshin si dy foto — i injorojmë
    let lastTap = 0, submitTimer = null;
    $$('.pic-btn', picForm).forEach(b => b.addEventListener('click', () => {
      const now = Date.now();
      if (now - lastTap < 350 || seq.length >= 3) return;
      lastTap = now;
      seq.push(b.dataset.pic);
      b.classList.add('chosen'); setTimeout(() => b.classList.remove('chosen'), 250);
      render();
      if (seq.length === 3) { $('#seq').value = seq.join('-'); submitTimer = setTimeout(() => picForm.submit(), 900); }
    }));
    $('#seqClear')?.addEventListener('click', () => { clearTimeout(submitTimer); seq = []; render(); });
  }

  // Zgjedhja e fotove kur mësuesi cakton fjalëkalimin me foto
  $$('[data-picpicker]').forEach(box => {
    const out = document.getElementById(box.dataset.picpicker);
    const show = box.querySelector('.seq-dots');
    let seq = [];
    const render = () => {
      out.value = seq.length === 3 ? seq.join('-') : '';
      $$('span', show).forEach((d, i) => {
        const b = seq[i] !== undefined ? box.querySelector('.pic-btn[data-pic="' + seq[i] + '"] span') : null;
        d.textContent = b ? b.textContent : ''; d.classList.toggle('filled', !!b);
      });
    };
    $$('.pic-btn', box).forEach(b => b.addEventListener('click', () => { if (seq.length < 3) { seq.push(b.dataset.pic); render(); } }));
    box.querySelector('[data-picclear]')?.addEventListener('click', () => { seq = []; render(); });
  });

  // Shiriti i qasshmërisë (nxënësit)
  const applyA11y = () => {
    if (!document.body.classList.contains('role-student')) return;   // vetëm portali i nxënësit
    const fs = store.get('fs', 16);
    document.documentElement.style.setProperty('--fs', fs + 'px');
    const hc = store.get('hc', false);
    document.body.classList.toggle('hc', hc);
    const cb = $('[data-a11y="contrast"]'); if (cb) cb.setAttribute('aria-pressed', hc ? 'true' : 'false');
  };
  applyA11y();
  $$('[data-a11y]').forEach(b => b.addEventListener('click', () => {
    const a = b.dataset.a11y;
    if (a === 'bigger') store.set('fs', Math.min(26, store.get('fs', 16) + 2));
    if (a === 'smaller') store.set('fs', Math.max(14, store.get('fs', 16) - 2));
    if (a === 'contrast') store.set('hc', !store.get('hc', false));
    if (a === 'speak') speak(($('#main')?.innerText || '').slice(0, 1500));
    applyA11y();
    logPortal(a === 'bigger' ? 'Shkronja më të mëdha (A+)' : a === 'smaller' ? 'Shkronja më të vogla (A−)' : a === 'contrast' ? 'Kontrast i lartë' : 'Lexoje me zë faqen',
      a === 'contrast' ? (store.get('hc', false) ? 'on' : 'off') : (a === 'speak' ? '' : String(store.get('fs', 16)) + 'px'));
  }));

  function logPortal(label, value) {
    if (!document.body.classList.contains('role-student')) return;
    const csrf = document.querySelector('meta[name="csrf"]')?.content;
    if (!csrf) return;
    fetch((window.APP_BASE || '') + '/api/track.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json' },
      body: JSON.stringify({ type: 'events', book: 0, events: [{ f: 'portal_a11y', a: 'click', l: label, v: value, s: location.pathname.split('/').pop(), t: Math.round(Date.now() / 1000) }] })
    }).catch(() => {});
  }
  $$('[data-speak]').forEach(b => b.addEventListener('click', () => logPortal('Dëgjo udhëzimin', '')));

  function speak(text) {
    if (!('speechSynthesis' in window)) { alert((window.__t || String)('Shfletuesi nuk e mbështet leximin me zë.')); return; }
    if (speechSynthesis.speaking) { speechSynthesis.cancel(); return; }
    const u = new SpeechSynthesisUtterance(text);
    u.lang = (document.documentElement.lang || 'sq') === 'sq' ? 'sq-AL' : document.documentElement.lang; u.rate = 0.9;
    const v = speechSynthesis.getVoices().find(v => v.lang.toLowerCase().startsWith((document.documentElement.lang || 'sq').slice(0, 2)));
    if (v) u.voice = v;
    speechSynthesis.speak(u);
  }
  window.KD.speak = speak;
  $$('[data-speak]').forEach(b => b.addEventListener('click', () => speak(b.dataset.speak)));

  /* ---------------- Përdorimi pa internet (PWA) ---------------- */
  const base = (window.APP_BASE || '') + '/';
  const swOk = 'serviceWorker' in navigator && 'caches' in window && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname));
  if (swOk) {
    navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(() => {});
  }
  // gjatë daljes fshihen faqet e ruajtura të nxënësit
  $$('a[href$="logout.php"]').forEach(a => a.addEventListener('click', () => {
    try { navigator.serviceWorker && navigator.serviceWorker.controller && navigator.serviceWorker.controller.postMessage('logout'); } catch (e) { /* */ }
    try { caches.delete('kd-pages'); } catch (e) { /* */ }
  }));
  // shiriti "Pa internet"
  const banner = $('#netBanner');
  const net = () => { if (banner) banner.classList.toggle('show', !navigator.onLine); };
  window.addEventListener('online', net); window.addEventListener('offline', net); net();

  // butonat "📥 Pa internet" te librat
  const offBtns = $$('[data-offline-book]');
  if (swOk && offBtns.length) {
    offBtns.forEach(async btn => {
      btn.hidden = false;
      const id = btn.dataset.offlineBook;
      const doneKey = 'offline_' + id;
      const markDone = () => { btn.dataset.state = 'done'; btn.textContent = '✅ Gati pa internet'; };
      if (store.get(doneKey, false)) {
        try { const c = await caches.open('kd-books'); const meta = store.get(doneKey + '_entry', ''); if (!meta || await c.match(meta)) markDone(); } catch (e) { /* */ }
      }
      btn.addEventListener('click', async () => {
        if (btn.dataset.state === 'busy') return;
        if (!navigator.onLine) { alert((window.__t || String)('Duhet internet për ta shkarkuar librin.')); return; }
        btn.dataset.state = 'busy';
        try {
          const r = await fetch(base + 'api/book_files.php?book=' + id, { credentials: 'same-origin' });
          const data = await r.json();
          if (!data.ok) throw new Error(data.error);
          const c = await caches.open('kd-books');
          const files = data.files; let n = 0;
          for (let i = 0; i < files.length; i += 6) {
            await Promise.all(files.slice(i, i + 6).map(async f => {
              const abs = new URL(f, location.origin).toString();
              if (!(await c.match(abs))) { const res = await fetch(abs, { credentials: 'same-origin' }); if (res.ok) await c.put(abs, res); }
              n++;
            }));
            btn.textContent = '⏳ ' + Math.round(n * 100 / files.length) + '%';
          }
          const pages = await caches.open('kd-pages');
          for (const pg of [data.player, base + 'student/index.php']) {
            try { const res = await fetch(pg, { credentials: 'same-origin' }); if (res.ok) await pages.put(new URL(pg, location.origin).toString(), res); } catch (e) { /* */ }
          }
          store.set(doneKey, true); store.set(doneKey + '_entry', new URL(data.entry, location.origin).toString());
          markDone();
        } catch (e) {
          btn.dataset.state = ''; btn.textContent = '⚠️ Provo përsëri';
        }
      });
    });
  }
})();
