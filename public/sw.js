/*
 * MIA — service worker des NOTIFICATIONS (tâches programmées), et rien d'autre.
 *
 * Aucun gestionnaire `fetch` : il ne met rien en cache et ne s'interpose
 * jamais entre l'application et le réseau — une page servie par un cache
 * périmé après un déploiement serait pire que pas de notification du tout.
 *
 * Servi à la racine (portée « / ») : une notification ouvre n'importe quelle
 * page de l'application. Le message arrive chiffré (RFC 8291) ; le navigateur
 * le déchiffre avant de le remettre ici, en JSON : titre, texte, adresse.
 */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/** Adresse de la même origine seulement : un message ne fait jamais sortir de MIA. */
function sameOriginPath(raw) {
    try {
        const url = new URL(typeof raw === 'string' && raw !== '' ? raw : '/', self.location.origin);
        return url.origin === self.location.origin ? url.pathname + url.search + url.hash : '/';
    } catch (_e) {
        return '/';
    }
}

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (_e) {
        data = {};
    }
    const title = typeof data.title === 'string' && data.title !== '' ? data.title.slice(0, 120) : 'MIA';
    const options = {
        body: typeof data.body === 'string' ? data.body.slice(0, 300) : '',
        icon: '/assets/img/icons/icon-192.png',
        data: { url: sameOriginPath(data.url) },
    };
    if (typeof data.tag === 'string' && data.tag !== '') {
        // La notification suivante de la même tâche remplace la précédente.
        options.tag = data.tag.slice(0, 64);
        options.renotify = true;
    }
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(sameOriginPath(event.notification.data && event.notification.data.url), self.location.origin).href;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (client.url === target && 'focus' in client) {
                return client.focus();
            }
        }
        return self.clients.openWindow(target);
    })());
});
