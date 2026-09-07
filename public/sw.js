// Bump on any change to the caching strategy or the shell list.
const CACHE = 'dansk-shell-v3';

// Only genuinely immutable assets are precached. The HTML deliberately is not:
// see the navigation branch below.
const ASSETS = ['/manifest.webmanifest', '/icon.svg'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // A scored quiz must never be answerable offline: caching /api/* would ship the
  // correct answers to the client.
  if (e.request.method !== 'GET' || url.pathname.startsWith('/api/')) return;

  // HTML is NETWORK-FIRST. Cache-first here meant a stale page was served forever
  // and no interface change ever reached an existing visitor. The cache is kept
  // only as an offline fallback.
  const isDocument = e.request.mode === 'navigate'
    || (e.request.headers.get('accept') || '').includes('text/html');

  // Shared code is a static file that changes as often as the pages importing it.
  // Cache-first would pin an old copy behind the cache name until someone remembered to
  // bump it, and a forgotten bump ships a stale interface with nothing reporting it. It
  // is revalidated for the same reason the HTML is.
  const isCode = url.origin === self.location.origin && /\.(js|css)$/.test(url.pathname);

  if (isDocument || isCode) {
    e.respondWith(
      fetch(e.request)
        .then(res => {
          const copy = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
          return res;
        })
        .catch(() => caches.match(e.request).then(hit => hit || caches.match('/')))
    );
    return;
  }

  // Static assets stay cache-first; they are versioned by the cache name.
  e.respondWith(
    caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {});
      return res;
    }))
  );
});
