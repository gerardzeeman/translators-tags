// Standaard-bestemming als de webhook geen url meestuurt. Momenteel is dit
// de enige soort pushmelding die de app verstuurt (plan §7); zodra dat
// verandert, moet dit een payload-afhankelijke keuze worden.
const DEFAULT_CLICK_URL = '/cgk-rijnsburg-nieuwsoverzicht';

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
