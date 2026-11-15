/* Klasa Digjitale — Service Worker (përdorim pa internet) */
'use strict';
const VERSION = 'v5';
const SHELL = 'kd-shell-' + VERSION;
const PAGES = 'kd-pages';
const BOOKS = 'kd-books';
const BASE = new URL('./', self.location).pathname;          // p.sh. "/" ose "/klasa/"

const SHELL_FILES = [
  'offline.html', 'assets/css/app.css', 'assets/js/app.js', 'assets/js/player.js',
  'assets/fonts/AtkinsonHyperlegible-400-latin.woff2', 'assets/fonts/AtkinsonHyperlegible-700-latin.woff2',
  'assets/fonts/AtkinsonHyperlegible-400-latin-ext.woff2', 'assets/fonts/AtkinsonHyperlegible-700-latin-ext.woff2',
  'assets/icons/icon-192.png', 'manifest.webmanifest'
].map(f => BASE + f);

self.addEventListener('install', e => {
  e.waitUntil(caches.open(SHELL).then(c => c.addAll(SHELL_FILES)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('kd-shell-') && k !== SHELL).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

/* Librat shfaqen brenda lexuesit (iframe nga i njëjti domen). Disa hostingje u shtojnë skedarëve statikë
   "X-Frame-Options: DENY" / "frame-ancestors 'none'" — këtu i heqim për librat, që të hapen gjithmonë. */
function frameable(res) {
  if (!res || res.type === 'opaque' || res.type === 'opaqueredirect' || res.status === 0) return res;
  const h = new Headers(res.headers);
  if (!h.has('X-Frame-Options') && !h.has('Content-Security-Policy')) return res;
  h.delete('X-Frame-Options'); h.delete('Content-Security-Policy');
  h.set('X-Frame-Options', 'SAMEORIGIN');
  return new Response(res.body, { status: res.status, statusText: res.statusText, headers: h });
}

function stripQuery(url) { const u = new URL(url); u.search = ''; return u.toString(); }

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith(BASE)) return;
  const path = url.pathname.slice(BASE.length);

  // API, hyrja/dalja, admini — gjithmonë nga rrjeti
  if (path.startsWith('api/') || path.startsWith('admin/') || path.startsWith('install/') || /^(login|logout|parent|verify)\.php/.test(path)) return;

  // Librat: nga memoria e shpejtë së pari (cache-first), pastaj rrjeti
  if (path.startsWith('content/books/')) {
    e.respondWith(caches.open(BOOKS).then(async c => {
      const key = stripQuery(req.url);
      const hit = await c.match(key);
      if (hit) return frameable(hit);
      try {
        const res = frameable(await fetch(req));
        if (res.ok) c.put(key, res.clone());
        return res;
      } catch (err) {
        return new Response('', { status: 504, statusText: 'Offline' });
      }
    }));
    return;
  }

  // Skedarët statikë: memoria + përditësim në sfond
  if (path.startsWith('assets/') || path === 'manifest.webmanifest') {
    e.respondWith(caches.open(SHELL).then(async c => {
      const hit = await c.match(req, { ignoreSearch: true });
      const net = fetch(req).then(res => { if (res.ok) c.put(stripQuery(req.url), res.clone()); return res; }).catch(() => hit);
      return hit || net;
    }));
    return;
  }

  // Faqet e nxënësit: rrjeti së pari, ruhen për përdorim pa internet
  if (req.mode === 'navigate') {
    const cacheable = path.startsWith('student/');
    e.respondWith(fetch(req).then(res => {
      if (cacheable && res.ok && !res.redirected) { const copy = res.clone(); caches.open(PAGES).then(c => c.put(req, copy)); }
      return res;
    }).catch(async () => {
      const hit = (await caches.match(req)) || (cacheable && !path.startsWith('student/player.php') ? await caches.match(req, { ignoreSearch: true }) : null);
      return hit || caches.match(BASE + 'offline.html');
    }));
  }
});

// Fshirja e memories kur nxënësi del (kompjuter i përbashkët)
self.addEventListener('message', e => {
  if (e.data === 'logout') caches.delete(PAGES);
});
