// assets/controllers/historical_recompute_controller.js
//
// Standalone "Herbereken dit hoofdstuk/boek" button for the historical-
// alignment book/chapter overview pages (plan sectie 6/7). The verse review
// page has its own recompute action built into historical_alignment_
// controller.js; this is the same call, minus everything that controller
// needs for the word-linking UI itself.

import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export default class extends Controller {
    static targets = ['status']
    static values = {
        url:       String,
        scopeType: String,
        usfm:      String,
        chapter:   String,
    }

    async recompute(event) {
        const btn = event.currentTarget
        btn.disabled = true
        this.#setStatus('Bezig met herberekenen…')

        try {
            const resp = await fetch(this.urlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body:    JSON.stringify({
                    scope_type: this.scopeTypeValue,
                    usfm:       this.usfmValue,
                    chapter:    this.chapterValue || null,
                }),
            })
            const data = await resp.json()
            if (data.success) {
                this.#setStatus('Klaar — pagina wordt herladen…')
                setTimeout(() => window.location.reload(), 600)
            } else {
                this.#setStatus(`Mislukt: ${data.error ?? 'onbekende fout'}`)
                btn.disabled = false
            }
        } catch {
            this.#setStatus('Mislukt: netwerkfout')
            btn.disabled = false
        }
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) this.statusTarget.textContent = msg
    }
}
