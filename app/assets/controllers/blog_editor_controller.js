// assets/controllers/blog_editor_controller.js
import { Controller } from '@hotwired/stimulus'

// Drives the "+ Bijbelvers" picker dialog in the blog Markdown editor: lets
// the author pick book/chapter/verse (and options) via dropdowns instead of
// hand-typing the ```bijbelvers fenced-block syntax, then inserts the
// generated block at the cursor position in the textarea.
export default class extends Controller {
    static targets = [
        'textarea', 'bibleDialog',
        'boek', 'hoofdstuk', 'vers', 'aantalVerzen',
        'woordRangeGroup', 'woordChips',
        'toonVertaling', 'vertalingGroup', 'vertaling',
        'alleenVertaling', 'highlightLinks', 'layoutGroup', 'layout',
        'institutieDialog',
        'instBoek', 'instHoofdstukGroup', 'instHoofdstuk', 'instAantal', 'instSectie',
        'instZinRangeGroup', 'instZinnen',
        'instTaal', 'instTekenGroup', 'instZinText', 'instLayoutGroup', 'instLayout',
        'imageInput', 'imageStatus',
    ]
    static values = {
        boekenUrl: String,
        verzenUrl: String,
        woordenUrl: String,
        vertalingenUrl: String,
        institutieStructuurUrl: String,
        institutieSectiesUrl: String,
        institutieZinnenUrl: String,
        imageUploadUrl: String,
        imageUploadToken: String,
    }

    connect() {
        this.books = null
        this.translations = null
        this.words = []
        this.selectedWords = { from: null, to: null }

        this.instStructuur = null
        this.instSections = []
        this.instZinRows = []
        this.selectedZinnen = { from: null, to: null }
        this.instTekenRange = { from: null, to: null }
    }

    async openBible() {
        if (!this.books) await this.#loadBooks()
        if (!this.translations) await this.#loadTranslations()
        this.toggleVertalingFields()
        this.bibleDialogTarget.showModal()
    }

    closeBible() {
        this.bibleDialogTarget.close()
    }

    async onBoekChange() {
        const book = this.books.find((b) => b.nameNl === this.boekTarget.value)
        const count = book ? book.chapterCount : 1
        this.hoofdstukTarget.innerHTML = this.#options(this.#range(1, count))
        await this.onHoofdstukChange()
    }

    async onHoofdstukChange() {
        const params = new URLSearchParams({ boek: this.boekTarget.value, hoofdstuk: this.hoofdstukTarget.value })
        const res = await fetch(`${this.verzenUrlValue}?${params}`)
        const data = await res.json()
        this.versTarget.innerHTML = this.#options(this.#range(1, data.verseCount || 1))
        await this.onVersChange()
    }

    async onVersChange() {
        this.selectedWords = { from: null, to: null }
        const params = new URLSearchParams({
            boek: this.boekTarget.value,
            hoofdstuk: this.hoofdstukTarget.value,
            vers: this.versTarget.value,
        })
        const res = await fetch(`${this.woordenUrlValue}?${params}`)
        const data = await res.json()
        this.words = data.words || []
        this.#renderChips()
    }

    onAantalVerzenChange() {
        const multi = parseInt(this.aantalVerzenTarget.value || '1', 10) > 1
        this.woordRangeGroupTarget.hidden = multi
        if (multi) {
            this.selectedWords = { from: null, to: null }
            this.#updateChipStyles()
        }
    }

    toggleWordChip(event) {
        const pos = parseInt(event.currentTarget.dataset.position, 10)
        const { from, to } = this.selectedWords
        if (from === null || to !== null) {
            this.selectedWords = { from: pos, to: null }
        } else {
            this.selectedWords = { from: Math.min(from, pos), to: Math.max(from, pos) }
        }
        this.#updateChipStyles()
    }

    toggleVertalingFields() {
        const toon = this.toonVertalingTarget.checked
        const alleen = this.alleenVertalingTarget.checked
        this.vertalingGroupTarget.hidden = !(toon || alleen)
        this.layoutGroupTarget.hidden = !(toon && !alleen)
    }

    confirmBible() {
        const boek = this.boekTarget.value
        const hoofdstuk = this.hoofdstukTarget.value
        const vers = this.versTarget.value
        const aantalVerzen = parseInt(this.aantalVerzenTarget.value || '1', 10)
        const toonVertaling = this.toonVertalingTarget.checked
        const alleenVertaling = this.alleenVertalingTarget.checked
        const highlightLinks = this.highlightLinksTarget.checked

        const lines = ['```bijbelvers', `boek: ${boek}`, `hoofdstuk: ${hoofdstuk}`, `vers: ${vers}`]

        if (aantalVerzen > 1) {
            lines.push(`aantal_verzen: ${aantalVerzen}`)
        } else if (this.selectedWords.from !== null) {
            lines.push(`woord_van: ${this.selectedWords.from}`)
            lines.push(`woord_tot: ${this.selectedWords.to ?? this.selectedWords.from}`)
        }

        if (toonVertaling || alleenVertaling) {
            lines.push(`toon_vertaling: ${toonVertaling ? 'ja' : 'nee'}`)
            lines.push(`vertaling: ${this.vertalingTarget.value}`)
            lines.push(`alleen_vertaling: ${alleenVertaling ? 'ja' : 'nee'}`)
        }
        lines.push(`highlight_links: ${highlightLinks ? 'ja' : 'nee'}`)
        if (toonVertaling && !alleenVertaling) {
            lines.push(`layout: ${this.layoutTarget.value}`)
        }
        lines.push('```')

        this.#insertAtCursor('\n' + lines.join('\n') + '\n')
        this.closeBible()
    }

    // ── Institutie-tekst dialog ─────────────────────────────────────────────

    async openInstitutie() {
        if (!this.instStructuur) await this.#loadInstitutieStructuur()
        this.onInstTaalChange()
        this.institutieDialogTarget.showModal()
    }

    closeInstitutie() {
        this.institutieDialogTarget.close()
    }

    async onInstBoekChange() {
        const isFront = this.instBoekTarget.value === 'front'
        this.instHoofdstukGroupTarget.hidden = isFront
        if (!isFront) {
            const book = this.instStructuur.books.find((b) => String(b.book) === this.instBoekTarget.value)
            this.instHoofdstukTarget.innerHTML = this.#options(this.#range(1, book ? book.chapterCount : 1))
        }
        await this.onInstHoofdstukChange()
    }

    async onInstHoofdstukChange() {
        const params = new URLSearchParams({
            boek: this.instBoekTarget.value,
            hoofdstuk: this.instBoekTarget.value === 'front' ? '0' : this.instHoofdstukTarget.value,
        })
        const res = await fetch(`${this.institutieSectiesUrlValue}?${params}`)
        const data = await res.json()
        this.instSections = data.sections || []
        this.instSectieTarget.innerHTML = this.instSections
            .map((s) => `<option value="${s.section}">${s.section}: ${this.#esc(s.preview)}</option>`)
            .join('')
        await this.onInstSectieChange()
    }

    async onInstSectieChange() {
        this.selectedZinnen = { from: null, to: null }
        const ref = this.#instRef()
        const params = new URLSearchParams({ referentie: ref })
        const res = await fetch(`${this.institutieZinnenUrlValue}?${params}`)
        const data = await res.json()
        this.instZinRows = data.rows || []
        this.#renderZinRows()
        this.#updateInstZinText()
    }

    onInstAantalChange() {
        const multi = parseInt(this.instAantalTarget.value || '1', 10) > 1
        this.instZinRangeGroupTarget.hidden = multi
        if (multi) {
            this.selectedZinnen = { from: null, to: null }
            this.instTekenRange = { from: null, to: null }
        }
        this.onInstTaalChange()
    }

    toggleZinRow(event) {
        const idx = parseInt(event.currentTarget.dataset.index, 10)
        const { from, to } = this.selectedZinnen
        if (from === null || to !== null) {
            this.selectedZinnen = { from: idx, to: null }
        } else {
            this.selectedZinnen = { from: Math.min(from, idx), to: Math.max(from, idx) }
        }
        this.instTekenRange = { from: null, to: null }
        this.#updateZinRowStyles()
        this.#updateInstZinText()
    }

    onInstTaalChange() {
        const taal = this.instTaalTarget.value
        const multi = parseInt(this.instAantalTarget.value || '1', 10) > 1
        this.instLayoutGroupTarget.hidden = taal !== 'beide'
        this.instTekenGroupTarget.hidden = taal === 'beide' || multi
        this.instTekenRange = { from: null, to: null }
        this.#updateInstZinText()
    }

    onInstTextSelect() {
        const sel = window.getSelection()
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return
        const container = this.instZinTextTarget
        const range = sel.getRangeAt(0)
        if (!container.contains(range.commonAncestorContainer)) return

        const full = container.textContent
        const preRange = document.createRange()
        preRange.selectNodeContents(container)
        preRange.setEnd(range.startContainer, range.startOffset)
        const from = preRange.toString().length + 1
        const to = from + range.toString().length - 1
        this.instTekenRange = { from, to: Math.min(to, full.length) }
    }

    clearInstTekenRange() {
        this.instTekenRange = { from: null, to: null }
        window.getSelection()?.removeAllRanges()
    }

    confirmInstitutie() {
        const referentie = this.#instRef()
        const aantal = parseInt(this.instAantalTarget.value || '1', 10)
        const taal = this.instTaalTarget.value

        const lines = ['```institutie', `referentie: ${referentie}`]

        if (aantal > 1) {
            lines.push(`aantal: ${aantal}`)
        } else if (this.selectedZinnen.from !== null) {
            lines.push(`zin_van: ${this.selectedZinnen.from}`)
            lines.push(`zin_tot: ${this.selectedZinnen.to ?? this.selectedZinnen.from}`)
        }

        lines.push(`taal: ${taal}`)

        if (aantal === 1 && taal !== 'beide' && this.instTekenRange.from !== null) {
            lines.push(`teken_van: ${this.instTekenRange.from}`)
            lines.push(`teken_tot: ${this.instTekenRange.to}`)
        }
        if (taal === 'beide') {
            lines.push(`layout: ${this.instLayoutTarget.value}`)
        }
        lines.push('```')

        this.#insertAtCursor('\n' + lines.join('\n') + '\n')
        this.closeInstitutie()
    }

    async #loadInstitutieStructuur() {
        const res = await fetch(this.institutieStructuurUrlValue)
        this.instStructuur = await res.json()
        const options = []
        if (this.instStructuur.hasFrontMatter) options.push('<option value="front">Voorwoord</option>')
        this.instStructuur.books.forEach((b) => options.push(`<option value="${b.book}">Boek ${b.book}</option>`))
        this.instBoekTarget.innerHTML = options.join('')
        await this.onInstBoekChange()
    }

    #renderZinRows() {
        this.instZinnenTarget.innerHTML = this.instZinRows
            .map(
                (r) =>
                    `<div class="embed-zin-row" data-index="${r.index}" data-action="click->blog-editor#toggleZinRow">` +
                    `<span class="embed-zin-index">${r.index}</span>` +
                    `<span class="embed-zin-preview"><em>${this.#esc(r.la_text)}</em><br>${this.#esc(r.nl_text)}</span>` +
                    `</div>`
            )
            .join('')
        this.#updateZinRowStyles()
    }

    #updateZinRowStyles() {
        const { from, to } = this.selectedZinnen
        this.instZinnenTarget.querySelectorAll('.embed-zin-row').forEach((row) => {
            const idx = parseInt(row.dataset.index, 10)
            const inRange = from !== null && idx >= from && idx <= (to ?? from)
            row.classList.toggle('is-selected', inRange)
        })
    }

    #updateInstZinText() {
        const taal = this.instTaalTarget.value
        if (taal === 'beide' || !this.instZinTextTarget) {
            if (this.instZinTextTarget) this.instZinTextTarget.textContent = ''
            return
        }
        const { from, to } = this.selectedZinnen
        const rows = from !== null ? this.instZinRows.slice(from - 1, (to ?? from)) : this.instZinRows
        const field = taal === 'latijn' ? 'la_text' : 'nl_text'
        this.instZinTextTarget.textContent = rows.map((r) => r[field]).join(' ')
    }

    #instRef() {
        if (this.instBoekTarget.value === 'front') {
            return `Inst. front.${this.instSectieTarget.value}`
        }
        return `Inst. ${this.instBoekTarget.value}.${this.instHoofdstukTarget.value}.${this.instSectieTarget.value}`
    }

    // ── Afbeelding uploaden ──────────────────────────────────────────────────

    openImageUpload() {
        this.imageInputTarget.click()
    }

    async onImageFileSelected(event) {
        const file = event.target.files[0]
        event.target.value = '' // allow re-selecting the same file later
        if (!file) return

        const defaultAlt = file.name.replace(/\.[^.]+$/, '')
        const alt = window.prompt('Alt-tekst voor deze afbeelding (voor toegankelijkheid):', defaultAlt)
        if (alt === null) return // cancelled

        this.#setImageStatus('Uploaden…')

        const formData = new FormData()
        formData.append('afbeelding', file)
        formData.append('_csrf_token', this.imageUploadTokenValue)

        try {
            const res = await fetch(this.imageUploadUrlValue, { method: 'POST', body: formData })
            const data = await res.json()
            if (!res.ok) {
                this.#setImageStatus(data.error || 'Uploaden mislukt.', true)
                return
            }
            this.#insertAtCursor(`![${alt}](${data.url})`)
            this.#setImageStatus('')
        } catch {
            this.#setImageStatus('Uploaden mislukt (netwerkfout).', true)
        }
    }

    #setImageStatus(message, isError = false) {
        this.imageStatusTarget.hidden = !message
        this.imageStatusTarget.textContent = message
        this.imageStatusTarget.classList.toggle('is-error', isError)
    }

    async #loadBooks() {
        const res = await fetch(this.boekenUrlValue)
        this.books = await res.json()
        this.boekTarget.innerHTML = this.books
            .map((b) => `<option value="${this.#esc(b.nameNl)}">${this.#esc(b.nameNl)}</option>`)
            .join('')
        await this.onBoekChange()
    }

    async #loadTranslations() {
        const res = await fetch(this.vertalingenUrlValue)
        this.translations = await res.json()
        this.vertalingTarget.innerHTML = this.translations
            .map((t) => `<option value="${this.#esc(t.code)}">${this.#esc(t.abbreviation)}</option>`)
            .join('')
    }

    #renderChips() {
        this.woordChipsTarget.innerHTML = this.words
            .map(
                (w) =>
                    `<button type="button" class="embed-word-chip" data-position="${w.position}" data-action="blog-editor#toggleWordChip">${this.#esc(w.text)}</button>`
            )
            .join('')
        this.#updateChipStyles()
    }

    #updateChipStyles() {
        const { from, to } = this.selectedWords
        this.woordChipsTarget.querySelectorAll('.embed-word-chip').forEach((chip) => {
            const pos = parseInt(chip.dataset.position, 10)
            const inRange = from !== null && pos >= from && pos <= (to ?? from)
            chip.classList.toggle('is-selected', inRange)
        })
    }

    #insertAtCursor(text) {
        const el = this.textareaTarget
        const start = el.selectionStart ?? el.value.length
        const end = el.selectionEnd ?? el.value.length
        el.value = el.value.slice(0, start) + text + el.value.slice(end)
        const pos = start + text.length
        el.focus()
        el.setSelectionRange(pos, pos)
    }

    #options(values) {
        return values.map((n) => `<option value="${n}">${n}</option>`).join('')
    }

    #range(from, to) {
        const arr = []
        for (let i = from; i <= to; i++) arr.push(i)
        return arr
    }

    // Safe for both text-node AND attribute-value contexts: the textContent/
    // innerHTML roundtrip only escapes &, <, > (text-node rules), so quotes are
    // escaped explicitly too -- needed since some call sites interpolate this
    // into value="..." (e.g. #loadBooks, #loadTranslations), where an
    // unescaped `"` would break out of the attribute.
    #esc(s) {
        const div = document.createElement('div')
        div.textContent = s
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;')
    }
}
