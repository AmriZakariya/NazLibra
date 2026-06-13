const CACHE_NAME = 'librairepro-v3';
const STATIC_ASSETS = [
    '/manifest.json',
    '/icons/icon-32x32.png',
    '/icons/icon-192x192.png',
    '/icons/icon-512x512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        }).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
            );
        })
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);
    const acceptsJson = event.request.headers.get('accept')?.includes('application/json');
    const isDynamicAppData = acceptsJson
        || url.pathname.startsWith('/caisse')
        || url.pathname.startsWith('/catalogue')
        || url.pathname.startsWith('/modules')
        || url.pathname.startsWith('/stock')
        || url.pathname.startsWith('/parametres')
        || url.pathname.startsWith('/api/')
        || url.pathname.startsWith('/livewire/')
        || url.pathname.startsWith('/broadcasting/');

    if (isDynamicAppData) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Navigation requests must stay fresh in a POS/back-office app.
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request));
        return;
    }

    const isStaticAsset = url.origin === self.location.origin && (
        url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/icons/')
        || url.pathname.endsWith('.css')
        || url.pathname.endsWith('.js')
        || url.pathname.endsWith('.woff')
        || url.pathname.endsWith('.woff2')
        || url.pathname.endsWith('.png')
        || url.pathname.endsWith('.jpg')
        || url.pathname.endsWith('.jpeg')
        || url.pathname.endsWith('.webp')
        || url.pathname.endsWith('.svg')
    );

    if (!isStaticAsset) {
        event.respondWith(fetch(event.request));
        return;
    }

    // Static assets only — cache-first.
    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) return cached;
            return fetch(event.request).then((response) => {
                if (!response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }
                const responseToCache = response.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, responseToCache);
                });
                return response;
            });
        })
    );
});
