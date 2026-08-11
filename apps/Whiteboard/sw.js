const CACHE_VERSION = 'whiteboard-v3';
const CACHE_NAME = CACHE_VERSION;
const ASSETS =[
    './',
    './index.php',
    './css/style.css',
    './js/app.js',
    './manifest.json',
    './icon.svg',
    'https://unpkg.com/lucide@latest'
];

console.log('SW: Script Loaded and Parsing...');

// Helper to enforce a timeout on network requests
const fetchWithTimeout = (request, timeout = 2500) => {
    // LAYER 4: Instant Skip if hardware reports offline
    if (navigator.onLine === false) {
        return Promise.reject(new Error("Offline (Instant Skip)"));
    }
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeout);
    return fetch(request, { signal: controller.signal }).finally(() => clearTimeout(id));
};

self.addEventListener('install', (e) => {
    console.log('SW: Install Event Fired');
    e.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            console.log('SW: Attempting to pre-cache assets...');
            // We use a map to try each asset individually so one failure doesn't kill the SW
            const cachePromises = ASSETS.map(async (url) => {
                try {
                    const res = await fetch(url);
                    if (!res.ok) throw new Error(`Fetch failed for ${url}`);
                    return cache.put(url, res);
                } catch (err) {
                    console.warn(`SW: Failed to pre-cache ${url}:`, err);
                }
            });
            return Promise.all(cachePromises);
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    console.log('SW: Active and Claiming Clients');
    e.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(keys.map((key) => {
                if (key !== CACHE_NAME) return caches.delete(key);
            }));
        })
    );
    return self.clients.claim();
});

self.addEventListener('fetch', (e) => {
    if (e.request.method !== 'GET') return;

    const url = new URL(e.request.url);

    // 1. NAVIGATION FALLBACK
    // Try network with a strict 2.5s timeout to prevent "Lie-fi" hangs.
    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetchWithTimeout(e.request, 2500).catch(() => {
                return caches.match('./index.php', { ignoreSearch: true }) || 
                       caches.match('./index.php') || 
                       caches.match('./');
            })
        );
        return;
    }

    // 2. CORE ASSETS (JS/CSS)
    const isCoreAsset = url.pathname.includes('js/app.js') || 
                        url.pathname.includes('css/style.css');

    if (isCoreAsset) {
        e.respondWith(
            fetchWithTimeout(e.request, 2000).then((response) => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
                return response;
            }).catch(() => {
                return caches.match(e.request, { ignoreSearch: true });
            })
        );
        return;
    }

    // 3. MEDIA ASSET EXCLUSION
    // Do NOT cache media assets in the SW Cache API. 
    // They are already stored in IndexedDB (The Bunker).
    const isMediaAsset = url.pathname.includes('/data/assets/');
    if (isMediaAsset) {
        e.respondWith(fetch(e.request));
        return;
    }

    // STRATEGY: Cache-First for everything else (Icons, etc)
    e.respondWith(
        caches.match(e.request).then((cached) => {
            return cached || fetch(e.request).then((response) => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
                return response;
            });
        })
    );
});