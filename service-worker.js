// NekiRot Quetta - Service Worker
const CACHE_NAME = 'nekirot-v1';

// Files to cache
const STATIC_ASSETS = [
    '/nekirot/nekirot-php/',
    '/nekirot/nekirot-php/index.php',
    '/nekirot/nekirot-php/login.php',
    '/nekirot/nekirot-php/register.php',
    '/nekirot/nekirot-php/assets/css/nekirot-theme.css',
    '/nekirot/nekirot-php/assets/js/main.js'
];

// Install event
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                console.log('Cache opened');
                return cache.addAll(STATIC_ASSETS);
            })
    );
});

// Activate event
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.map(function(cacheName) {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});

// Fetch event
self.addEventListener('fetch', function(event) {
    event.respondWith(
        caches.match(event.request)
            .then(function(response) {
                // Cache hit - return response
                if (response) {
                    return response;
                }
                return fetch(event.request)
                    .then(function(response) {
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME)
                            .then(function(cache) {
                                cache.put(event.request, responseToCache);
                            });
                        return response;
                    });
            })
    );
});

// Push notification
self.addEventListener('push', function(event) {
    const data = event.data.json();
    const options = {
        body: data.body,
        icon: '/nekirot/nekirot-php/assets/icons/icon-192.png',
        badge: '/nekirot/nekirot-php/assets/icons/icon-72.png',
        vibrate: [200, 100, 200],
        data: {
            url: data.url || '/nekirot/nekirot-php/'
        }
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Notification click
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
