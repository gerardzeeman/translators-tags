import { Controller } from '@hotwired/stimulus'

// Live preview of a spelling rule while it is being written on
// /commentaren/spelling: how often it applies in Los's commentary, which
// words it changes, and a few examples in context (SpellingRuleController::
// preview). A word picked from the suggestions arrives as the old form; it
// is copied into the new form so it only needs editing.
export default class extends Controller {
    static targets = ['kind', 'source', 'target', 'exceptions', 'preview']
    static values = { previewUrl: String }

    #timer = null
    #request = 0

    connect() {
        if (this.sourceTarget.value && !this.targetTarget.value) {
            this.targetTarget.value = this.sourceTarget.value
            this.targetTarget.focus()
            this.targetTarget.select()
        }
        if (this.sourceTarget.value) this.update()
    }

    update() {
        clearTimeout(this.#timer)
        this.#timer = setTimeout(() => this.#load(), 300)
    }

    async #load() {
        const kind = this.kindTargets.find((k) => k.checked)?.value ?? 'word'
        const source = this.sourceTarget.value.trim()
        const target = this.targetTarget.value.trim()
        if (!source || !target) {
            this.previewTarget.innerHTML = ''
            return
        }
        const params = new URLSearchParams({ kind, source, target, exceptions: this.exceptionsTarget.value })
        const request = ++this.#request
        this.previewTarget.innerHTML = '<p class="spelling-muted">Voorbeeld laden…</p>'
        try {
            const response = await fetch(`${this.previewUrlValue}?${params}`, { headers: { Accept: 'application/json' } })
            const data = await response.json()
            if (request !== this.#request) return // a newer request is under way
            this.#render(data)
        } catch {
            if (request === this.#request) this.previewTarget.innerHTML = '<p class="spelling-muted">Voorbeeld niet beschikbaar.</p>'
        }
    }

    #render(data) {
        const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
        if (data.error) {
            this.previewTarget.innerHTML = `<p class="spelling-preview-error">${esc(data.error)}</p>`
            return
        }
        let html = `<p><strong>${data.hits}</strong> keer in Los' commentaar${data.hits ? ':' : '.'}</p>`
        if (data.words.length) {
            html += '<ul class="spelling-preview-words">' + data.words.map((w) =>
                `<li><span class="spelling-old">${esc(w.old)}</span> → <span class="spelling-new">${esc(w.new)}</span> <span class="spelling-muted">${w.count}×</span></li>`
            ).join('') + '</ul>'
        }
        if (data.examples.length) {
            html += '<ul class="spelling-preview-examples">' + data.examples.map((e) =>
                `<li>${esc(e.before)}<span class="spelling-old">${esc(e.old)}</span><span class="spelling-new">${esc(e.new)}</span>${esc(e.after)}</li>`
            ).join('') + '</ul>'
        }
        this.previewTarget.innerHTML = html
    }
}
