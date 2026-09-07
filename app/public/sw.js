// Standaard-bestemming als de webhook geen url meestuurt. Momenteel is dit
// de enige soort pushmelding die de app verstuurt (plan §7); zodra dat
// verandert, moet dit een payload-afhankelijke keuze worden.
const DEFAULT_CLICK_URL = '/cgk-rijnsburg-nieuwsoverzicht';

// Zonder deze twee regels blijft een browser die al een oudere versie van
// deze service worker draait daar willekeurig lang op hangen -- pas bij de
// eerstvolgende (door de browser zelf bepaalde, kan uren duren) her-check
// wordt een update opgemerkt, en dan nog pas actief na sluiten van alle
// tabs. skipWaiting()+clients.claim() forceren een nieuwe versie direct
// actief te worden zodra de browser 'm heeft opgehaald.
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    event.waitUntil(self.registration.showNotification(data.title || 'Alef-Omega', {
        body: data.body || '',
        icon: '/favicon.png',
        data: { url: data.url || DEFAULT_CLICK_URL },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(clients.openWindow(event.notification.data.url || DEFAULT_CLICK_URL));
});
