// assets/controllers/translation_linker_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['wordA', 'wordB', 'status', 'linksTable', 'wordIdsA', 'wordIdsB']
    static values  = { saveUrl: String, deleteUrl: String, resetUrl: String }

    #selectedA = null   // { el, id }
    #selectedB = null   // { el, id }

    // ── Select word from translation A ───────────────────────────────────────

    selectWordA(event) {
        event.stopPropagation()
        const el   = event.currentTarget
        const twId = el.dataset.twId

        // Toggle deselect
        if (this.#selectedA?.id === twId) {
            el.classList.remove('trans-word-active')
            this.#selectedA = null
            this.#updateStatus()
            return
        }

        // Deselect previous A
        if (this.#selectedA) {
            this.#selectedA.el.classList.remove('trans-word-active')
        }

        this.#selectedA = { el, id: twId }
        el.classList.add('trans-word-active')

        this.#updateStatus()
        this.#tryAutoSave()
    }

    // ── Select word from translation B ───────────────────────────────────────

    selectWordB(event) {
        event.stopPropagation()
        const el   = event.currentTarget
        const twId = el.dataset.twId

        // Toggle deselect
        if (this.#selectedB?.id === twId) {
            el.classList.remove('trans-word-active')
            this.#selectedB = null
            this.#updateStatus()
            return
        }

        // Deselect previous B
        if (this.#selectedB) {
            this.#selectedB.el.classList.remove('trans-word-active')
        }

        this.#selectedB = { el, id: twId }
        el.classList.add('trans-word-active')

        this.#updateStatus()
        this.#tryAutoSave()
    }

    // ── Auto-save when both sides selected ───────────────────────────────────

    async #tryAutoSave() {
        if (!this.#selectedA || !this.#selectedB) return

        const wordAId = parseInt(this.#selectedA.id)
        const wordBId = parseInt(this.#selectedB.id)

        this.#setStatus('Opslaan…')

        try {
            const resp = await fetch(this.saveUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ word_a_id: wordAId, word_b_id: wordBId, method: 'manual' }),
            })
            const data = await resp.json()
            if (!data.success) throw new Error(data.error || 'Save failed')

            this.#setStatus('✓ Koppeling opgeslagen. Pagina wordt herladen…')
            this.#clearSelection()
            setTimeout(() => window.location.reload(), 600)

        } catch (err) {
            this.#setStatus(`Fout: ${err.message}`)
            this.#clearSelection()
        }
    }

    // ── Delete link ───────────────────────────────────────────────────────────

    async deleteLink(event) {
        event.stopPropagation()
        const btn    = event.currentTarget
        const wordAId = parseInt(btn.dataset.wordA)
        const wordBId = parseInt(btn.dataset.wordB)

        try {
            const resp = await fetch(this.deleteUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ word_a_id: wordAId, word_b_id: wordBId }),
            })
            const data = await resp.json()
            if (!data.success) throw new Error(data.error || 'Delete failed')

            this.#setStatus('Koppeling verwijderd. Pagina wordt herladen…')
            setTimeout(() => window.location.reload(), 400)

        } catch (err) {
            this.#setStatus(`Fout bij verwijderen: ${err.message}`)
        }
    }

    // ── Reset auto-links for this verse ──────────────────────────────────────

    async resetAuto(event) {
        event?.stopPropagation()

        if (!confirm('Alle automatische koppelingen voor dit vers verwijderen?')) return

        const idsA = this.hasWordIdsATarget
            ? this.wordIdsATarget.dataset.ids.split(',').filter(Boolean).map(Number)
            : []
        const idsB = this.hasWordIdsBTarget
            ? this.wordIdsBTarget.dataset.ids.split(',').filter(Boolean).map(Number)
            : []

        try {
            const resp = await fetch(this.resetUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ ids_a: idsA, ids_b: idsB }),
            })
            const data = await resp.json()
            if (!data.success) throw new Error(data.error || 'Reset failed')

            this.#setStatus(`${data.deleted} auto-koppelingen verwijderd. Pagina wordt herladen…`)
            setTimeout(() => window.location.reload(), 600)

        } catch (err) {
            this.#setStatus(`Fout bij resetten: ${err.message}`)
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    #clearSelection() {
        if (this.#selectedA) { this.#selectedA.el.classList.remove('trans-word-active'); this.#selectedA = null }
        if (this.#selectedB) { this.#selectedB.el.classList.remove('trans-word-active'); this.#selectedB = null }
    }

    #updateStatus() {
        if (!this.#selectedA && !this.#selectedB) {
            this.#setStatus('Klik een woord links, dan een woord rechts om te koppelen.')
        } else if (this.#selectedA && !this.#selectedB) {
            this.#setStatus(`"${this.#selectedA.el.textContent.trim()}" geselecteerd. Klik nu een woord rechts.`)
        } else if (!this.#selectedA && this.#selectedB) {
            this.#setStatus(`"${this.#selectedB.el.textContent.trim()}" geselecteerd. Klik nu een woord links.`)
        }
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) this.statusTarget.textContent = msg
    }
}
