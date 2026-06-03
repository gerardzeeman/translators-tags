// assets/controllers/translation_linker_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['wordA', 'wordB', 'status', 'saveBtn', 'linksTable', 'wordIdsA', 'wordIdsB']
    static values  = { saveUrl: String, deleteUrl: String, resetUrl: String }

    // Maps of twId → element for current selection
    #selectedAs = new Map()
    #selectedBs = new Map()

    // ── Select word from translation A ───────────────────────────────────────

    selectWordA(event) {
        event.stopPropagation()
        const el   = event.currentTarget
        const twId = el.dataset.twId

        if (this.#selectedAs.has(twId)) {
            this.#selectedAs.delete(twId)
            el.classList.remove('trans-word-active')
        } else {
            this.#selectedAs.set(twId, el)
            el.classList.add('trans-word-active')
        }

        this.#updateStatus()
    }

    // ── Select word from translation B ───────────────────────────────────────

    selectWordB(event) {
        event.stopPropagation()
        const el   = event.currentTarget
        const twId = el.dataset.twId

        if (this.#selectedBs.has(twId)) {
            this.#selectedBs.delete(twId)
            el.classList.remove('trans-word-active')
        } else {
            this.#selectedBs.set(twId, el)
            el.classList.add('trans-word-active')
        }

        this.#updateStatus()
    }

    // ── Save all selected A×B pairs ───────────────────────────────────────────

    async saveLinks(event) {
        event?.stopPropagation()
        if (!this.#selectedAs.size || !this.#selectedBs.size) return

        this.#setStatus('Opslaan…')
        if (this.hasSaveBtnTarget) this.saveBtnTarget.disabled = true

        const pairs = []
        for (const idA of this.#selectedAs.keys()) {
            for (const idB of this.#selectedBs.keys()) {
                pairs.push([parseInt(idA), parseInt(idB)])
            }
        }

        try {
            for (const [wordAId, wordBId] of pairs) {
                const resp = await fetch(this.saveUrlValue, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ word_a_id: wordAId, word_b_id: wordBId, method: 'manual' }),
                })
                const data = await resp.json()
                if (!data.success) throw new Error(data.error || 'Save failed')
            }

            this.#setStatus(`✓ ${pairs.length} koppeling(en) opgeslagen. Pagina wordt herladen…`)
            this.#clearSelection()
            setTimeout(() => window.location.reload(), 600)

        } catch (err) {
            this.#setStatus(`Fout: ${err.message}`)
            if (this.hasSaveBtnTarget) this.saveBtnTarget.disabled = false
        }
    }

    // ── Delete link ───────────────────────────────────────────────────────────

    async deleteLink(event) {
        event.stopPropagation()
        const btn     = event.currentTarget
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
        this.#selectedAs.forEach(el => el.classList.remove('trans-word-active'))
        this.#selectedBs.forEach(el => el.classList.remove('trans-word-active'))
        this.#selectedAs.clear()
        this.#selectedBs.clear()
    }

    #updateStatus() {
        const nA = this.#selectedAs.size
        const nB = this.#selectedBs.size
        const canSave = nA > 0 && nB > 0

        if (this.hasSaveBtnTarget) {
            this.saveBtnTarget.style.display = canSave ? 'inline-block' : 'none'
            this.saveBtnTarget.disabled = false
        }

        if (nA === 0 && nB === 0) {
            this.#setStatus('Klik een of meerdere woorden links, dan rechts, dan "Opslaan".')
        } else if (canSave) {
            this.#setStatus(`${nA} woord(en) links × ${nB} woord(en) rechts geselecteerd — ${nA * nB} koppeling(en) worden opgeslagen.`)
        } else if (nA > 0) {
            this.#setStatus(`${nA} woord(en) links geselecteerd. Selecteer nu woord(en) rechts.`)
        } else {
            this.#setStatus(`${nB} woord(en) rechts geselecteerd. Selecteer nu woord(en) links.`)
        }
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) this.statusTarget.textContent = msg
    }
}
