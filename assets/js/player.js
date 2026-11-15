/* Klasa Digjitale — lexuesi i librave SCORM/ADT me ndjekje të progresit */
(function () {
  'use strict';
  const P = window.PLAYER;
  const iframe = document.getElementById('book');
  const pageById = {}, pageByHref = {};
  P.pages.forEach(p => { pageById[p.id] = p; pageByHref[p.href.split('/').pop()] = p; });
  const visited = new Set(P.visited);
  const done = new Set(P.done);
  let current = null;            // section_id aktual
  let lastActive = Date.now();   // ndërveprimi i fundit
  let sessionSec = P.today || 0;
  let pendingSec = 0;
  const IDLE_LIMIT = 120 * 1000; // pas 2 min pa aktivitet nuk llogaritet koha

  /* ---------------- Rrjeti ---------------- */
  let sessionId = null;
  // Radha pa internet: kërkesat që dështojnë ruhen dhe dërgohen kur kthehet interneti
  const QKEY = 'kd_queue_' + P.book;
  const qGet = () => { try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; } };
  const qSet = (a) => { try { localStorage.setItem(QKEY, JSON.stringify(a.slice(-500))); } catch (e) { /* */ } };
  function enqueue(payload) {
    if (payload.type === 'start' || payload.type === 'ticket') return;
    payload.q = 1; payload.ts = payload.ts || Math.round(Date.now() / 1000);
    const a = qGet(); a.push(payload); qSet(a);
  }
  let flushing = false;
  async function flushQueue() {
    if (flushing || !navigator.onLine) return;
    const a = qGet(); if (!a.length) return;
    flushing = true;
    try {
      while (a.length) {
        const r = await fetch(P.api, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf, 'Accept': 'application/json' }, body: JSON.stringify(a[0]) });
        if (!r.ok && r.status !== 400) break;          // 400 = e pavlefshme → hidhet
        a.shift(); qSet(a);
      }
      if (!a.length) toast('☁️ Puna e bërë pa internet u ruajt!');
    } catch (e) { /* ende pa rrjet */ }
    flushing = false;
  }
  window.addEventListener('online', () => setTimeout(flushQueue, 1500));
  setInterval(flushQueue, 30000);
  setTimeout(flushQueue, 3000);

  function send(payload, beacon) {
    payload.book = P.book;
    if (sessionId) payload.session = sessionId;
    payload.ts = payload.ts || Math.round(Date.now() / 1000);
    const body = JSON.stringify(payload);
    if (!navigator.onLine) { enqueue(payload); return Promise.resolve(null); }
    if (beacon && navigator.sendBeacon) {
      const ok = navigator.sendBeacon(P.api + '?csrf=' + encodeURIComponent(P.csrf), new Blob([body], { type: 'application/json' }));
      if (!ok) enqueue(payload);
      return Promise.resolve(null);
    }
    return fetch(P.api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.csrf, 'Accept': 'application/json' },
      body
    }).then(r => {
      if (r.ok) return r.json();
      if (r.status >= 500) enqueue(payload);
      return null;
    }).catch(() => { enqueue(payload); return null; });
  }

  /* ---------------- UI ---------------- */
  const toastEl = document.getElementById('toast');
  let toastT;
  function toast(msg) {
    if (window.__t) msg = window.__t(msg);
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toastT); toastT = setTimeout(() => toastEl.classList.remove('show'), 2600);
  }
  function renderProgress() {
    const total = P.pages.length || 1;
    const n = P.pages.filter(p => visited.has(p.id)).length;
    const pct = Math.round(n * 100 / total);
    const bar = document.querySelector('.pp .pbar');
    bar.querySelector('span').style.width = pct + '%';
    bar.setAttribute('aria-valuenow', pct);
    bar.className = 'pbar ' + (pct >= 80 ? 'good' : pct >= 40 ? 'mid' : 'low');
    document.getElementById('ppText').textContent = n + '/' + total + ' ' + ((window.I18N && window.I18N['faqe']) || 'faqe');
    document.getElementById('starCount').textContent = done.size;
  }
  function renderTimer() {
    const m = Math.floor(sessionSec / 60), s = sessionSec % 60;
    document.getElementById('timer').textContent = m + ':' + String(s).padStart(2, '0');
  }
  renderProgress();
  renderTimer();

  /* ---------------- SCORM 1.2 API ---------------- */
  const cmi = Object.assign({}, P.cmi);
  let dirty = false, lastErr = '0';
  const scormCommit = (beacon) => {
    if (!dirty) return 'true';
    dirty = false;
    send({ type: 'scorm', data: {
      lesson_location: cmi['cmi.core.lesson_location'] || '',
      lesson_status: cmi['cmi.core.lesson_status'] || '',
      score_raw: cmi['cmi.core.score.raw'] || '',
      suspend_data: cmi['cmi.suspend_data'] || ''
    } }, beacon);
    return 'true';
  };
  window.API = {
    LMSInitialize: () => { lastErr = '0'; return 'true'; },
    LMSFinish: () => { scormCommit(true); return 'true'; },
    LMSGetValue: (k) => {
      lastErr = '0';
      if (k === 'cmi.core._children') return 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time';
      if (k === 'cmi.core.score._children') return 'raw,min,max';
      return cmi[k] !== undefined ? String(cmi[k]) : '';
    },
    LMSSetValue: (k, v) => {
      lastErr = '0'; cmi[k] = String(v); dirty = true;
      if (k === 'cmi.core.lesson_location') onSection(String(v), 'scorm');
      return 'true';
    },
    LMSCommit: () => scormCommit(false),
    LMSGetLastError: () => lastErr,
    LMSGetErrorString: (c) => ({ '0': 'No error', '101': 'General exception', '201': 'Invalid argument', '401': 'Not implemented' }[c] || ''),
    LMSGetDiagnostic: () => ''
  };
  // SCORM 2004 (minimal) — vendos vlerat kryesore në të njëjtën strukturë
  const map04 = { 'cmi.location': 'cmi.core.lesson_location', 'cmi.completion_status': 'cmi.core.lesson_status', 'cmi.success_status': 'cmi.core.lesson_status', 'cmi.score.raw': 'cmi.core.score.raw', 'cmi.suspend_data': 'cmi.suspend_data', 'cmi.learner_id': 'cmi.core.student_id', 'cmi.learner_name': 'cmi.core.student_name', 'cmi.entry': 'cmi.core.entry' };
  window.API_1484_11 = {
    Initialize: () => 'true', Terminate: () => { scormCommit(true); return 'true'; },
    GetValue: (k) => window.API.LMSGetValue(map04[k] || k),
    SetValue: (k, v) => window.API.LMSSetValue(map04[k] || k, v),
    Commit: () => scormCommit(false), GetLastError: () => '0', GetErrorString: () => '', GetDiagnostic: () => ''
  };

  /* ---------------- Faqet ---------------- */
  function onSection(id, src) {
    if (!id) return;
    const p = pageById[id];
    if (!p && P.type === 'adt') return;
    const sec = p ? id : 'sco';
    if (sec === current) return;
    quietUntil = Date.now() + 1500;
    flushTime();
    current = sec;
    cmi['cmi.core.lesson_location'] = sec; dirty = true;
    const isNew = !visited.has(sec);
    visited.add(sec);
    renderProgress();
    send({ type: 'view', section: sec }).then(r => {
      if (r && isNew && r.pages_visited && r.pages_visited % 10 === 0) toast('🎉 Bravo! ' + r.pages_visited + ' faqe të lexuara!');
    });
  }

  function detectSection(win) {
    try {
      const doc = win.document;
      const meta = doc.querySelector('meta[name="title-id"]');
      if (meta && pageById[meta.content]) return meta.content;
      const file = win.location.pathname.split('/').pop();
      if (pageByHref[file]) return pageByHref[file].id;
      const sec = doc.querySelector('[data-section-id]');
      if (sec && pageById[sec.getAttribute('data-section-id')]) return sec.getAttribute('data-section-id');
    } catch (e) { /* jashtë domenit */ }
    return P.type === 'adt' ? null : 'sco';
  }

  /* ---------------- Koha ---------------- */
  const markActive = () => { lastActive = Date.now(); };
  ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove'].forEach(ev => window.addEventListener(ev, markActive, { passive: true }));
  setInterval(() => {
    if (document.visibilityState !== 'visible' || Date.now() - lastActive > IDLE_LIMIT || !current) return;
    sessionSec++; pendingSec++;
    renderTimer();
    if (pendingSec >= 20) flushTime();
  }, 1000);
  function flushTime(beacon) {
    if (pendingSec <= 0 || !current) return;
    const s = pendingSec; pendingSec = 0;
    send({ type: 'beat', section: current, seconds: s }, beacon);
  }
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') { flushTime(true); scormCommit(true); } });
  window.addEventListener('pagehide', () => { flushTime(true); scormCommit(true); });

  /* ---------------- Ushtrimet ---------------- */
  const norm = v => String(v == null ? '' : v).trim().toLowerCase().replace(/\s+/g, ' ');
  function itemState(el) {
    // renditja: aria-invalid vendoset edhe para kontrollit → besohet vetëm kur libri ka shfaqur verdiktin
    if (el.parentElement && el.parentElement.closest('[data-activity-order-list]') && !el.ownerDocument.querySelector('[data-order-verdict]')) return null;
    const probe = [el, el.parentElement, el.closest('label'), el.closest('.activity-option')].filter(Boolean);
    for (const x of probe) {
      const inv = x.getAttribute('aria-invalid');
      if (inv === 'false') return 'correct';
      if (inv === 'true') return 'wrong';
    }
    for (const x of probe) {
      const c = x.className && x.className.baseVal === undefined ? String(x.className) : '';
      if (/\b(border|bg|ring)-green-\d00\b/.test(c) || x.getAttribute('data-correct') === 'true') return 'correct';
      if (/\b(border|bg|ring)-red-\d00\b/.test(c) || x.getAttribute('data-correct') === 'false') return 'wrong';
    }
    return null;
  }
  function itemLabel(doc, el) {
    const clean = t => String(t || '').replace(/\s+/g, ' ').trim().slice(0, 160);
    const a = el.getAttribute('aria-label');
    if (a) return clean(a);
    const lb = el.getAttribute('aria-labelledby');   // pyetja e ushtrimit (p.sh. te përgjigjet e hapura)
    if (lb) { const t = lb.split(/\s+/).map(id => { const n = doc.getElementById(id); return n ? n.textContent : ''; }).join(' '); if (clean(t)) return clean(t); }
    if (el.id) { const l = doc.querySelector('label[for="' + el.id + '"]'); if (l) return clean(l.textContent); }
    const wrap = el.closest('label, .activity-option');
    if (wrap && clean(wrap.textContent)) return clean(wrap.textContent);
    return clean(el.textContent) || el.getAttribute('placeholder') || el.getAttribute('data-activity-item') || '';
  }
  function itemValue(el) {
    const tag = el.tagName;
    // renditja (lart/poshtë): vlera = pozicioni aktual i kartës (1, 2, 3…)
    const list = el.parentElement && el.parentElement.closest('[data-activity-order-list]');
    if (list && el.parentElement === list) return String(Array.from(list.children).filter(c => c.hasAttribute('data-activity-item')).indexOf(el) + 1);
    if (tag === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio')) return el.checked ? (el.value && el.value !== 'on' ? el.value : 'true') : '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return el.value || '';
    // elemente të tërhequra (lidh çiftet / renditje): ku ndodhet?
    const zone = el.parentElement && el.parentElement.closest('[data-dropzone], [data-dropzone-id], [id^="dropzone"], [data-zone]');
    if (zone) return zone.getAttribute('data-dropzone-id') || zone.getAttribute('data-dropzone') || zone.getAttribute('data-zone') || zone.id;
    if (el.getAttribute('aria-pressed') === 'true' || el.getAttribute('aria-checked') === 'true' || el.classList.contains('selected')) return 'true';
    return '';
  }

  function collect(win) {
    const doc = win.document;
    const section = doc.querySelector('[data-section-type]');
    const type = section ? section.getAttribute('data-section-type') : '';
    const ca = win.correctAnswers && typeof win.correctAnswers === 'object' ? win.correctAnswers : null;
    const groups = new Map();
    const add = (id, el) => { if (!groups.has(id)) groups.set(id, []); groups.get(id).push(el); };
    doc.querySelectorAll('[data-activity-item]').forEach(el => add(el.getAttribute('data-activity-item'), el));
    // opsionet e kuizit pa data-activity-item (krijohen nga libri gjatë hapjes)
    doc.querySelectorAll('.activity-option').forEach((el, i) => {
      if (el.hasAttribute('data-activity-item') || el.querySelector('[data-activity-item]')) return;
      add(el.getAttribute('data-id') || el.id || ('opsioni-' + (i + 1)), el);
    });
    // fusha shkrimi pa shenjë ushtrimi (libra më të vjetër / ushtrime të veçanta)
    if (!groups.size && section) {
      section.querySelectorAll('textarea, input[type="text"], input:not([type]), select').forEach((el, i) => add(el.id || el.name || ('fusha-' + (i + 1)), el));
    }
    const items = [];
    groups.forEach((els, id) => {
      let value = '', state = null;
      els.forEach(el => { const v = itemValue(el); if (v) value = value ? value + ', ' + v : v; const s = itemState(el); if (s) state = state === 'wrong' ? 'wrong' : s; });
      items.push({ id, label: itemLabel(doc, els[0]), value, state, expected: ca ? ca[id] : undefined });
    });
    const noKey = !ca || Object.values(ca).every(v => v === '' || v == null);
    // libri e ka treguar vetë saktë/gabim (p.sh. kuizi që përgjigjet menjëherë), edhe pse çelësi i përgjigjeve s'është i dukshëm
    const byState = noKey && !/open_ended/.test(type) && items.some(it => it.state);
    const openEnded = !byState && (/open_ended/.test(type) || noKey);
    let total = items.length, correct = 0, wrong = 0, empty = 0, score = null;
    items.forEach(it => { if (it.value === '') empty++; });
    if (byState) {
      const judged = items.filter(it => it.state === 'correct' || it.state === 'wrong');
      correct = judged.filter(it => it.state === 'correct').length; wrong = judged.length - correct;
      items.forEach(it => { if (!it.state) it.state = it.value === '' ? 'empty' : null; });
      total = judged.length; empty = 0;
      score = Math.round(100 * correct / Math.max(1, judged.length));
    } else if (!openEnded) {
      let targets = 0, extra = 0; correct = 0; wrong = 0;
      items.forEach(it => {
        const exp = it.expected;
        const hasTarget = !(exp === '' || exp === false || exp == null);
        if (hasTarget) {
          targets++;
          const match = it.state === 'correct' || (it.state !== 'wrong' && it.value !== '' && (norm(it.value) === norm(exp) || (exp === true && it.value !== '')));
          if (match) { correct++; it.state = 'correct'; }
          else if (it.value === '') it.state = 'empty';
          else { wrong++; it.state = 'wrong'; }
        } else if (it.value !== '' && it.state !== 'correct') { extra++; wrong++; it.state = 'wrong'; }
      });
      total = targets;
      score = Math.round(100 * correct / Math.max(1, targets + extra));
    } else {
      items.forEach(it => { if (it.value === '') it.state = 'empty'; else it.state = null; });
      correct = items.filter(it => it.value !== '').length;
    }
    const live = Array.from(doc.querySelectorAll('[role="status"], [role="alert"], [aria-live]')).map(e => e.innerText.trim()).filter(Boolean)[0] || '';
    items.forEach(it => { delete it.expected; });
    return { type, total, correct, wrong, empty, score, items, feedback: live.replace(/\s+/g, ' ').slice(0, 250) };
  }

  // Çdo ndërveprim që vlerësohet ruhet si tentativë: (1) kur nxënësi shtyp "Dërgo/Kontrollo",
  // (2) kur libri e tregon menjëherë saktë/gabim (kuizi) — pa pasur nevojë për "Dërgo".
  let capTimer = null, lastSig = '', lastSigAt = 0;
  function captureAttempt(win, delay) {
    const sec = current;
    clearTimeout(capTimer);
    capTimer = setTimeout(() => {
      let res;
      try { res = collect(win); } catch (e) { return; }
      if (!res.items.length || (res.empty === res.items.length && !res.items.some(it => it.state))) return; // asgjë e plotësuar
      // mos e ruaj dy herë të njëjtën gjendje (p.sh. "Dërgo" + sinjali i librit)
      const sig = sec + '|' + JSON.stringify(res.items.map(it => [it.id, it.value, it.state]));
      if (sig === lastSig && Date.now() - lastSigAt < 15000) return;
      lastSig = sig; lastSigAt = Date.now();
      send({ type: 'attempt', section: sec, activity_type: res.type, total: res.total, correct: res.correct, wrong: res.wrong, empty: res.empty, score: res.score, answers: { items: res.items }, feedback: res.feedback })
        .then(r => {
          if (!navigator.onLine) { done.add(sec); renderProgress(); toast('📴 U ruajt në pajisje — do të dërgohet kur të kthehet interneti.'); return; }
          const first = !done.has(sec);
          done.add(sec); renderProgress();
          if (res.score === null) toast(first ? '⭐ Bravo! Përgjigjet iu dërguan mësuesit.' : '✅ U ruajt përsëri.');
          else if (res.score >= 80) toast('⭐ Shkëlqyeshëm! ' + res.score + '%');
          else toast((first ? '⭐ ' : '') + 'U ruajt (' + res.score + '%). Provo përsëri nëse do! 💪');
        });
    }, delay || 900);
  }
  function onSubmitClick(win) { captureAttempt(win, 900); }
  // Vëzhgon verdiktin e menjëhershëm të librit (aria-invalid te opsionet e kuizit)
  var quietUntil = 0;
  function watchVerdicts(win) {
    const doc = win.document;
    if (!win.MutationObserver || !doc.body) return;
    quietUntil = Date.now() + 1500;      // gjendja që libri vendos vetë kur hap faqen nuk është tentativë
    new win.MutationObserver(muts => {
      if (Date.now() < quietUntil) return;
      for (const m of muts) {
        const t = m.target;
        const v = t.getAttribute && t.getAttribute('aria-invalid');
        if ((v === 'true' || v === 'false') && t.closest && (t.closest('.activity-option') || t.closest('section[data-section-type="activity_quiz"]'))) { captureAttempt(win, 700); return; }
      }
    }).observe(doc.body, { subtree: true, attributes: true, attributeFilter: ['aria-invalid'] });
  }

  /* ---------------- Puna e shkruar (tekstet, shënimet) — ruhet në server edhe pa "Dërgo" ---------------- */
  const WORK_RE = /^pg\d+_sec\d+_/;
  function readWork() {
    const o = {};
    try { for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k && (WORK_RE.test(k) || k === 'notepad-content')) o[k] = String(localStorage.getItem(k) || ''); } } catch (e) { /* */ }
    return o;
  }
  let workSnap = readWork();
  const liveText = {};      // fushat pa ruajtje në libër: txt:<faqja>:<id>
  let liveSent = {};
  function flushWork(beacon) {
    const now = readWork(); const items = [];
    for (const k in now) if (now[k] !== workSnap[k]) items.push({ k, v: now[k].slice(0, 20000) });
    // çelësat që zhduken (p.sh. pastrimi i pajisjes) NUK fshijnë punën në server
    for (const k in liveText) if (liveText[k] !== liveSent[k]) { items.push({ k, v: liveText[k].slice(0, 20000) }); liveSent[k] = liveText[k]; }
    workSnap = now;
    if (items.length) send({ type: 'work', items: items.slice(0, 200) }, beacon);
  }
  setInterval(flushWork, 5000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushWork(true); });
  window.addEventListener('pagehide', () => flushWork(true));
  function onTyping(e) {
    const t = e.target;
    if (!t || !/^(INPUT|TEXTAREA)$/.test(t.tagName) || /^(checkbox|radio|button|submit|range|hidden|password)$/i.test(t.type || '')) return;
    if (t.getAttribute('data-aria-id') && current) return;   // ruhet nga libri vetë (localStorage) → kapet nga flushWork
    const id = t.getAttribute('data-activity-item') || t.getAttribute('aria-label') || t.name || t.id || ('fusha' + (Array.from(t.ownerDocument.querySelectorAll('input,textarea')).indexOf(t) + 1));
    if (!current) return;
    liveText['txt:' + current + ':' + String(id).slice(0, 80)] = String(t.value || '');
  }

  /* ---------------- Klikimet & veçoritë e qasshmërisë (vetëm për admin) ---------------- */
  const labels = P.labels || {};
  const nrm = s => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
  let evQueue = [];
  let lastEvKey = '', lastEvAt = 0;
  function track(feature, action, label, value) {
    const k = feature + '|' + action + '|' + label + '|' + value;
    const now = Date.now();
    if (k === lastEvKey && now - lastEvAt < 400) return;
    lastEvKey = k; lastEvAt = now;
    evQueue.push({ f: feature, a: action, l: String(label || '').slice(0, 120), v: value == null ? '' : String(value).slice(0, 120), s: current, t: Math.round(now / 1000) });
    if (evQueue.length >= 25) flushEvents();
  }
  function flushEvents(beacon) {
    if (!evQueue.length) return;
    const batch = evQueue.splice(0, 100);
    send({ type: 'events', events: batch }, beacon);
  }
  setInterval(flushEvents, 8000);
  window.addEventListener('pagehide', () => flushEvents(true));
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushEvents(true); });

  function labelOf(doc, el) {
    let l = el.getAttribute('aria-label') || '';
    const lb = el.getAttribute('aria-labelledby');
    if (!l && lb) l = lb.split(/\s+/).map(id => (doc.getElementById(id) || {}).textContent || '').join(' ');
    if (!l) l = el.getAttribute('title') || el.getAttribute('alt') || '';
    if (!l) l = (el.innerText || el.textContent || el.value || '').trim();
    return l.replace(/\s+/g, ' ').trim().slice(0, 120);
  }
  function featureOfLabel(l) {
    const n = nrm(l);
    if (!n) return null;
    if (labels[n]) return labels[n];
    for (const k in labels) { if (k.length > 3 && (n.startsWith(k) || k.startsWith(n) && n.length > 5)) return labels[k]; }
    if (/^(dërgo|dergo|submit|kontrollo|check)/.test(n)) return 'submit';
    if (/^(provo përsëri|rivendos|retry|reset)/.test(n)) return 'retry';
    if (/faqja e (ardhshme|mëparshme)|next page|previous page/.test(n)) return 'navigation';
    return null;
  }
  function classifyClick(doc, e) {
    const t = e.target;
    if (!t || !t.closest) return;
    const ctl = t.closest('button,[role="button"],[role="switch"],[role="tab"],[role="menuitem"],[role="menuitemradio"],[role="option"],[role="checkbox"],[role="radio"],[role="slider"],a[href],input,select,textarea,label,summary,img,[data-activity-item]');
    const el = ctl || t;
    // kutitë e fshehura të çelësave (switch) gjenerojnë klikim të dytë — injoroji
    if (el.tagName === 'INPUT' && /checkbox|radio/.test(el.type) && !el.closest('[data-activity-item], section[data-section-type^="activity"]')) return;
    const label = labelOf(doc, el);
    let feature = featureOfLabel(label);
    const inActivity = !!(el.closest('[data-activity-item]') || el.matches('input,textarea,select') && el.closest('section[data-section-type^="activity"]') || el.closest('.activity-underline-option'));
    if (!feature && inActivity) feature = 'activity';
    if (!feature && ctl && ctl.closest('#interface-container, #nav-container, [role="dialog"], nav, aside')) {
      // kontroll i ndërfaqes ADT që s'e njohim me emër — kërko fjalë kyçe
      const n = nrm(label);
      feature = /shenj|sign/.test(n) ? 'sign_language' : /lexim i leht|easy/.test(n) ? 'easy_read' : /shpjeg|explain/.test(n) ? 'eli5'
        : /fjalor|gloss/.test(n) ? 'glossary' : /rrokj|syllab/.test(n) ? 'syllables' : /zë|zë|audio|lexo|read|luaj|play|ndal/.test(n) ? 'tts'
        : /imazh|image|figur/.test(n) ? 'describe_images' : /thekso|highlight/.test(n) ? 'highlight' : /gjuh|lang/.test(n) ? 'language'
        : /tem|theme|madhës|size|font|kontrast|lëviz|motion/.test(n) ? 'display' : 'settings';
    }
    if (!feature) feature = 'content';
    const action = el.tagName === 'IMG' ? 'image' : (ctl ? 'click' : 'tap');
    track(feature, action, label || el.tagName.toLowerCase(), '');
    // gjendja e çelësave (on/off) pas klikimit
    if (ctl && /switch|checkbox|menuitemradio/.test(ctl.getAttribute('role') || '') || (ctl && ctl.hasAttribute && (ctl.hasAttribute('aria-pressed') || ctl.hasAttribute('aria-checked')))) {
      setTimeout(() => {
        const st = ctl.getAttribute('aria-checked') || ctl.getAttribute('aria-pressed') || (ctl.getAttribute('data-state') || '');
        if (st) track(feature, 'toggle', label, /true|checked|on/.test(st) ? 'on' : 'off');
      }, 250);
    }
  }
  // Cilësimet që libri ADT ruan në localStorage (lexim me zë, tema, gjuha, etj.)
  function storageFeature(k, v) {
    const kk = k.toLowerCase();
    if (kk === 'isplaying') return 'tts';
    if (kk === 'dockmenuvalue') { const vv = nrm(v).replace(/"/g, ''); return /toc|nav|index/.test(vv) ? 'toc' : /lang/.test(vv) ? 'language' : vv ? 'settings' : null; }
    if (/sign/.test(kk)) return 'sign_language';
    if (/easy/.test(kk)) return 'easy_read';
    if (/eli5|explain/.test(kk)) return 'eli5';
    if (/syllab/.test(kk)) return 'syllables';
    if (/gloss/.test(kk)) return 'glossary';
    if (/describe|alttext/.test(kk)) return 'describe_images';
    if (/highlight/.test(kk)) return 'highlight';
    if (/note/.test(kk)) return 'notepad';
    if (/autoplay|speed|voice|narrat|tts|readaloud|volume|mute/.test(kk)) return 'tts';
    if (/language|lang/.test(kk)) return 'language';
    if (/theme|size|motion|dock|font|contrast|zoom|spacing|state/.test(kk)) return 'display';
    if (/tutorial/.test(kk)) return 'tutorial';
    return null;
  }
  let lsSnap = {};
  let lsQuietUntil = Date.now() + 5000;   // vlerat fillestare që libri shkruan vetë nuk janë veprime të nxënësit
  function readLS() {
    const o = {};
    try { for (let i = 0; i < localStorage.length; i++) { const k = localStorage.key(i); if (k && !k.startsWith('kd_') && k.length < 60) o[k] = String(localStorage.getItem(k)).slice(0, 200); } } catch (e) { /* */ }
    return o;
  }
  lsSnap = readLS();
  function diffLS() {
    const now = readLS();
    if (Date.now() < lsQuietUntil) { lsSnap = now; return; }
    for (const k in now) {
      if (now[k] === lsSnap[k]) continue;
      const f = storageFeature(k, now[k]);
      if (f) {
        let v = now[k].replace(/^"|"$/g, '');
        if (k === 'isPlaying') v = v === 'true' ? 'play' : 'stop';
        track(f, 'setting', k, v);
      }
    }
    lsSnap = now;
  }
  setInterval(diffLS, 1000);
  // Përdorimi i tastierës (lexues ekrani / navigim pa maus) dhe shkurtoret
  let kbLogged = {};
  function onKey(e) {
    const tag = (e.target && e.target.tagName) || '';
    const typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable);
    if (e.key === 'Tab' || (!typing && /^Arrow/.test(e.key))) {
      const k = (current || '') + e.key;
      if (!kbLogged[k]) { kbLogged[k] = 1; track('screen_reader', 'keyboard', e.key === 'Tab' ? 'Navigim me Tab' : 'Shigjetat e tastierës', ''); }
    } else if (!typing && (e.altKey || /^[a-zA-Z]$/.test(e.key) || e.key === 'Escape')) {
      track('keyboard', 'shortcut', (e.altKey ? 'Alt+' : '') + e.key, '');
    }
  }
  // Ngjarjet e lexuesit (jashtë librit)
  document.querySelector('.player-bar a[href="index.php"]')?.addEventListener('click', () => { track('navigation', 'click', 'Kthehu te librat', ''); flushEvents(true); });
  document.querySelector('[data-open="dlgGuide"]')?.addEventListener('click', () => track('tutorial', 'click', 'Si punohet', ''));

  /* ---------------- Lidhja me iframe ---------------- */
  function hook() {
    let win;
    try { win = iframe.contentWindow; void win.document; } catch (e) { return; }
    const doc = win.document;
    lsQuietUntil = Math.max(lsQuietUntil, Date.now() + 3000);
    onSection(detectSection(win), 'load');
    ['pointerdown', 'keydown', 'wheel', 'touchstart', 'mousemove', 'input'].forEach(ev => doc.addEventListener(ev, markActive, { passive: true, capture: true }));
    doc.addEventListener('adt:page-changed', e => { const id = e && e.detail && e.detail.sectionId; if (id) onSection(id, 'event'); else setTimeout(() => onSection(detectSection(win), 'event'), 300); });
    doc.addEventListener('click', e => {
      const b = e.target.closest && e.target.closest('button, [role="button"], input[type="submit"]');
      if (!b) return;
      const t = ((b.innerText || b.value || '') + ' ' + (b.getAttribute('aria-label') || '')).trim();
      if (/^(Dërgo|Dergo|Submit|Kontrollo|Check|Verifiko)/i.test(t)) onSubmitClick(win);
    }, true);
    doc.addEventListener('click', e => { try { classifyClick(doc, e); } catch (err) { /* */ } }, true);
    doc.addEventListener('keydown', onKey, true);
    doc.addEventListener('input', onTyping, true);
    try { watchVerdicts(win); } catch (err) { /* */ }
  }
  // Nëse shfletuesi e bllokon librin herën e parë (p.sh. kokë sigurie nga hostingu ose memoria e vjetër),
  // e ringarkojmë vetë një herë — njësoj si "Refresh".
  let frameRetries = 0;
  iframe.addEventListener('load', () => {
    let ok = false;
    try { const d = iframe.contentDocument; ok = !!(d && d.URL && d.URL !== 'about:blank' && d.body); } catch (e) { ok = false; }
    if (!ok && frameRetries < 2) {
      frameRetries++;
      const src = iframe.getAttribute('src') || '';
      const clean = src.replace(/([?&])_r=\d+&?/, '$1').replace(/[?&]$/, '');
      setTimeout(() => { iframe.src = clean + (clean.includes('?') ? '&' : '?') + '_r=' + Date.now(); }, 400 * frameRetries);
      return;
    }
    hook();
  });
  // navigimi i butë (pa ringarkim) — kontroll periodik
  setInterval(() => { try { const id = detectSection(iframe.contentWindow); if (id) onSection(id, 'poll'); } catch (e) { /* */ } }, 2000);

  /* ---------------- Më ndihmo ---------------- */
  const dlgHelp = document.getElementById('dlgHelp');
  document.getElementById('helpBtn').addEventListener('click', () => {
    track('help', 'click', 'Më ndihmo', '');
    const p = pageById[current];
    document.getElementById('helpPage').textContent = p ? '📍 Do t\'i tregojmë mësuesit që je te faqja ' + (p.n || '') + '.' : '';
    dlgHelp.showModal();
  });
  document.getElementById('helpForm').addEventListener('submit', e => {
    e.preventDefault();
    const f = e.target;
    const btn = f.querySelector('button');
    btn.disabled = true;
    send({ type: 'ticket', section: current, category: f.category.value, message: f.message.value }).then(r => {
      btn.disabled = false;
      if (r && r.ok) { track('help', 'ticket', f.category.value, ''); dlgHelp.close(); f.reset(); toast('📨 Mesazhi u dërgua te mësuesi!'); }
      else toast('⚠️ Nuk u dërgua. Provo përsëri.');
    });
  });

  send({ type: 'start', device: /Mobi|Android|iPad|Tablet/i.test(navigator.userAgent) ? 'tablet/celular' : 'kompjuter' }).then(r => { if (r && r.session) sessionId = r.session; });

  if (P.firstVisit) {
    const g = document.getElementById('dlgGuide');
    if (g) setTimeout(() => g.showModal(), 600);
  }
})();
