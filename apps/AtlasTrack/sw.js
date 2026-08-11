// AtlasTrack Pass-Through Service Worker
// This file exists only to enable "Add to Home Screen" functionality.
// It does NOT cache any files to ensure the app is always live.

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    // Force network-only: No caching, always live.
    event.respondWith(fetch(event.request));
});