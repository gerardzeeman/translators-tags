// assets/controllers/review_lock_controller.js
//
// Per-scope soft lock for the alignment review UI (plan sectie 5): acquires
// a lock on connect, sends a heartbeat every ~2 min to keep it alive while
// the page stays open, and releases it on close/navigate. A conflict (lock
// held by someone else, or lost mid-session) shows a banner and marks the
// element read-only via the `review-lock-readonly` class -- it never
// blocks reading, only editing.
//
// Usage: data-controller="review-lock"
//        data-review-lock-scope-type-value="verse"
//        data-review-lock-scope-id-value="GEN.1.1"
//        data-review-lock-acquire-url-value="{{ path('app_review_lock_acquire') }}"
//        data-review-lock-heartbeat-url-value="{{ path('app_review_lock_heartbeat') }}"
//        data-review-lock-release-url-value="{{ path('app_review_lock_release') }}"
//        data-review-lock-csrf-token-value="{{ csrf_token('review_lock_api') }}"
//   optional targets: data-review-lock-target="banner" / "bannerText"

import { Controller } from '@hotwired/stimulus'

const HEARTBEAT_INTERVAL_MS = 120_000 // ~2 min, per plan sectie 5
const READONLY_CLASS = 'review-lock-readonly'

export default class extends Controller {
    static targets = ['banner', 'bannerText']
    static values  = {
        scopeType:    String,
        scopeId:      String,
        acquireUrl:   String,
        heartbeatUrl: String,
        releaseUrl:   String,
        csrfToken:    String,
    }

    #heartbeatTimer = null
    #released = false
    #boundBeforeUnload = null

    connect() {
        this.#boundBeforeUnload = () => this.#releaseSync()
        window.addEventListener('beforeunload', this.#boundBeforeUnload)
        this.#acquire()
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.#boundBeforeUnload)
        this.#stopHeartbeat()
        this.#releaseSync()
    }

    async #acquire() {
        const data = await this.#postJson(this.acquireUrlValue)

        if (data?.success) {
            this.#startHeartbeat()
            this.#setReadonly(false)
            this.#hideBanner()
        } else {
            this.#setReadonly(true)
            this.#showConflict(data?.conflict)
        }
    }

    #startHeartbeat() {
        this.#stopHeartbeat()
        this.#heartbeatTimer = setInterval(() => this.#heartbeat(), HEARTBEAT_INTERVAL_MS)
    }

    #stopHeartbeat() {
        if (this.#heartbeatTimer) {
            clearInterval(this.#heartbeatTimer)
            this.#heartbeatTimer = null
        }
    }

    async #heartbeat() {
        const data = await this.#postJson(this.heartbeatUrlValue)
        if (!data?.success) {
            this.#stopHeartbeat()
            this.#setReadonly(true)
            this.#showLost()
        }
    }

    #releaseSync() {
        if (this.#released) return
        this.#released = true

        fetch(this.releaseUrlValue, {
            method:    'POST',
            keepalive: true,
            headers:   { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
            body:      JSON.stringify({ scope_type: this.scopeTypeValue, scope_id: this.scopeIdValue }),
        }).catch(() => {})
    }

    async #postJson(url) {
        try {
            const resp = await fetch(url, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body:    JSON.stringify({ scope_type: this.scopeTypeValue, scope_id: this.scopeIdValue }),
            })
            return await resp.json()
        } catch {
            return null
        }
    }

    #setReadonly(readonly) {
        this.element.classList.toggle(READONLY_CLASS, readonly)
    }

    #showConflict(conflict) {
        if (!this.hasBannerTarget) return
        const name  = conflict?.user_display_name ?? 'iemand anders'
        const since = conflict
            ? new Date(conflict.locked_at).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })
            : '?'
        if (this.hasBannerTextTarget) {
            this.bannerTextTarget.textContent = `Wordt nu gereviewd door ${name}, sinds ${since}.`
        }
        this.bannerTarget.hidden = false
    }

    #showLost() {
        if (!this.hasBannerTarget) return
        if (this.hasBannerTextTarget) {
            this.bannerTextTarget.textContent = 'Je bewerkrechten zijn verlopen. Herlaad de pagina om verder te gaan.'
        }
        this.bannerTarget.hidden = false
    }

    #hideBanner() {
        if (this.hasBannerTarget) this.bannerTarget.hidden = true
    }
}
