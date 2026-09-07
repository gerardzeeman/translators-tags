import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

// Web Push VAPID keys are base64url; PushManager.subscribe() wants a raw Uint8Array.
function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - base64.length % 4) % 4)
    const base64Safe = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
    const raw = atob(base64Safe)
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)))
}

function keysEqual(a, b) {
    if (!a || !b || a.byteLength !== b.byteLength) return false
    const viewA = new Uint8Array(a)
    const viewB = new Uint8Array(b)
    return viewA.every((byte, i) => byte === viewB[i])
}

export default class extends Controller {
    static targets = ['toggle', 'status', 'unsupported']
    static values = { vapidPublicKey: String, subscribeUrl: String, unsubscribeUrl: String }

    async connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            this.#markUnsupported()
            return
        }

        try {
            this.registration = await navigator.serviceWorker.register('/sw.js')
        } catch {
            // bv. geen secure context, browserbeleid, of schijfquotum -- zelfde
            // eindtoestand als "niet ondersteund": geen bruikbare toggle.
            this.#markUnsupported()
            return
        }

        await this.#syncState()
    }

    #markUnsupported() {
        if (this.hasUnsupportedTarget) this.unsupportedTarget.hidden = false
        if (this.hasToggleTarget) this.toggleTarget.disabled = true
    }

    // Aan-/uitzetten via de checkbox (data-action="change->push-subscribe#toggle")
    async toggle(event) {
        try {
            if (event.target.checked) {
                await this.#subscribe()
            } else {
                await this.#unsubscribe()
            }
        } catch (error) {
            // Zonder deze catch verdwijnt een fout hier stil in de console --
            // de checkbox blijft dan "aan" staan terwijl er niets gebeurd is,
            // zonder dat de gebruiker enig signaal krijgt.
            console.error('push-subscribe', error)
            this.#setState(false, 'Inschrijven is niet gelukt door een onverwachte fout.')
        }
    }

    // Sleutelrotatie-detectie (plan §3.2, §6.2 stap 3): een bestaand
    // abonnement dat met een oudere VAPID-sleutel is aangemaakt kan met de
    // huidige sleutel nooit meer slagen -- lokaal opzeggen en de gebruiker
    // vragen opnieuw in te schakelen, in plaats van een dode toggle te tonen.
    async #syncState() {
        const subscription = await this.registration.pushManager.getSubscription()

        if (subscription) {
            const currentKey = urlBase64ToUint8Array(this.vapidPublicKeyValue).buffer
            if (!keysEqual(subscription.options?.applicationServerKey, currentKey)) {
                await subscription.unsubscribe()
                this.#setState(false, 'Meldingen zijn opnieuw ingeschakeld nodig na een sleutelwissel.')
                return
            }
        }

        this.#setState(!!subscription)
    }

    async #subscribe() {
        if (Notification.permission === 'denied') {
            this.#setState(false, 'Meldingen zijn geblokkeerd in je browserinstellingen.')
            return
        }

        const permission = await Notification.requestPermission()
        if (permission !== 'granted') {
            this.#setState(false)
            return
        }

        const subscription = await this.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(this.vapidPublicKeyValue),
        })

        const resp = await fetch(this.subscribeUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(subscription.toJSON()),
        })

        if (!resp.ok) {
            const body = await resp.json().catch(() => ({}))
            await subscription.unsubscribe()
            this.#setState(false, body.error ?? 'Inschrijven is niet gelukt.')
            return
        }

        this.#setState(true)
    }

    async #unsubscribe() {
        const subscription = await this.registration.pushManager.getSubscription()
        if (subscription) {
            await fetch(this.unsubscribeUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            })
            await subscription.unsubscribe()
        }
        this.#setState(false)
    }

    #setState(subscribed, message) {
        if (this.hasToggleTarget) this.toggleTarget.checked = subscribed
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message ?? (subscribed ? 'Meldingen staan aan.' : 'Meldingen staan uit.')
        }
    }
}
