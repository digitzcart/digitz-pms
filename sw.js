/* Digitz PMS service worker: app-shell precache (incl. Tesseract OCR), network-first API, Web Push. */
const VERSION = 'digitz-v1.0.0';
const SHELL = [
  './', './index.html', './manifest.json',
  './icons/icon-192.png', './icons/icon-512.png', './icons/maskable-512.png', './icons/apple-touch-icon.png',
  // Tesseract.js + core + English data, so OCR works offline
  './vendor/tesseract.min.js', './vendor/worker.min.js',
  './vendor/core/tesseract-core-simd-lstm.wasm.js', './vendor/core/tesseract-core-lstm.wasm.js',
  './vendor/lang/eng.traineddata.gz'
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (k !== VERSION) await caches.delete(k);
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (url.pathname.includes('/api/')) {
    // Network-first for API; offline fallback is a JSON error the app understands.
    if (req.method !== 'GET') return; // never cache writes
    e.respondWith(fetch(req).catch(() => new Response(JSON.stringify({ error: { code: 'OFFLINE', message_en: 'You are offline.', message_ml: 'നിങ്ങൾ ഓഫ്‌ലൈനാണ്.' } }),
      { status: 503, headers: { 'Content-Type': 'application/json' } })));
    return;
  }
  if (req.method !== 'GET') return;
  if (req.mode === 'navigate') {
    // Network-first for the shell document so updates arrive; cached copy offline.
    e.respondWith(fetch(req).then((res) => { const c = res.clone(); caches.open(VERSION).then((ca) => ca.put('./index.html', c)); return res; })
      .catch(() => caches.match('./index.html')));
    return;
  }
  // Cache-first for static assets (incl. OCR files)
  e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
    if (res.ok) { const c = res.clone(); caches.open(VERSION).then((ca) => ca.put(req, c)); }
    return res;
  })));
});

// Payload-less push: fetch the message text from the server using our endpoint as the key.
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let msgs = [];
    try {
      if (e.data) { const d = e.data.json(); msgs = [d]; }
    } catch (_) {}
    if (!msgs.length) {
      try {
        const sub = await self.registration.pushManager.getSubscription();
        const r = await fetch('./api/push.php?action=pending', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub && sub.endpoint }) });
        msgs = (await r.json()).messages || [];
      } catch (_) {}
    }
    if (!msgs.length) msgs = [{ title: 'Digitz PMS', body: 'You have new reminders.' }];
    const m = msgs[0];
    await self.registration.showNotification(m.title, { body: m.body, icon: './icons/icon-192.png', badge: './icons/icon-192.png', tag: 'digitz-reminder', renotify: true, data: { url: './index.html#/pdcs' } });
    if (self.navigator && 'setAppBadge' in self.navigator) try { await self.navigator.setAppBadge(msgs.length); } catch (_) {}
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = (e.notification.data && e.notification.data.url) || './index.html';
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) { if ('focus' in c) { c.navigate(target).catch(() => {}); return c.focus(); } }
    return self.clients.openWindow(target);
  })());
});

self.addEventListener('message', (e) => { if (e.data === 'skipWaiting') self.skipWaiting(); });
