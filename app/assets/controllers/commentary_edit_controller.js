import { Controller } from '@hotwired/stimulus'
import { confirmDialog } from '../confirm_dialog.js'

// Editing Calvin's commentary page (editors only: ROLE_EDIT_SPELLING), active
// while "Markeer wijzigingen moderne spelling" is on (spelling_marks_
// controller.js sets <html data-spelling-marks="on">):
//
//  - a word the spelling rules changed can be clicked: a small dropdown with
//    the rules for that old form (the default first), Los's own form, or back
//    to the default -- saved for that one spot (SpellingRuleController::
//    choose), the segment's modern column swapped for the answer;
//  - select a piece of text in any of the three columns (Latin, Los, modern)
//    to place a flag on it (TextFlagController): a category and a note;
//  - the flags are shown -- found again in the text by what was flagged and
//    the text around it -- as a highlight with a ⚑; click the ⚑ to edit,
//    resolve or remove it.
//
// Text is read as the reader sees it: popups, labels and note glyphs are not
// part of it (SKIP), in the flags as stored and when they are found again.
const SKIP = '.institutio-word-popup, .institutio-note-popup, .institutio-layer-label, .institutio-note-glyph, '
    + '.commentary-print-ref, .text-flag-icon, .commentary-nl-missing'
const CONTEXT = 40

export default class extends Controller {
    static values = {
        choiceUrl: String, choiceToken: String,
        flagsUrl: String, flagToken: String,
        categories: Object,
    }

    connect() {
        this.pop = null
        this.flagButton = null
        this.element.addEventListener('click', this.#onClick)
        document.addEventListener('mouseup', this.#onMouseUp)
        document.addEventListener('keydown', this.#onKey)
        document.addEventListener('click', this.#onDocumentClick, true)
        this.element.querySelectorAll('[data-segment-id]').forEach((section) => this.#showFlags(section))
    }

    disconnect() {
        this.element.removeEventListener('click', this.#onClick)
        document.removeEventListener('mouseup', this.#onMouseUp)
        document.removeEventListener('keydown', this.#onKey)
        document.removeEventListener('click', this.#onDocumentClick, true)
        this.#close()
    }

    get #active() {
        return document.documentElement.dataset.spellingMarks === 'on'
    }

    // ── Events ───────────────────────────────────────────────────────────────

    #onClick = (event) => {
        if (!this.#active) return
        const icon = event.target.closest('.text-flag-icon')
        if (icon) {
            event.preventDefault()
            this.#openFlag(icon)
            return
        }
        const word = event.target.closest('.spelling-choosable')
        if (word && window.getSelection().isCollapsed) {
            event.preventDefault()
            this.#openChoice(word)
        }
    }

    // a click outside the dropdown/form closes it
    #onDocumentClick = (event) => {
        if (this.pop && !this.pop.contains(event.target)
            && !event.target.closest('.spelling-choosable, .text-flag-icon, .text-flag-new')) {
            this.#close()
        }
    }

    #onKey = (event) => {
        if (event.key === 'Escape') this.#close()
    }

    #onMouseUp = (event) => {
        if (!this.#active || (this.pop && this.pop.contains(event.target)) || event.target.closest('.text-flag-new')) return
        setTimeout(() => this.#offerFlag(), 0) // after the selection has settled
    }

    // ── Choice per spot ──────────────────────────────────────────────────────

    #openChoice(word) {
        const options = JSON.parse(word.dataset.options || '[]')
        const chosen = word.dataset.chosen
        const original = word.dataset.original
        const item = (value, label, current) =>
            `<button type="button" class="commentary-edit-option${current ? ' is-current' : ''}" data-choice="${esc(value)}">${label}</button>`
        let html = `<div class="commentary-edit-title">Los: „${esc(original)}”</div>`
        options.forEach((o, i) => {
            const current = chosen === String(o.id) || (chosen === 'default' && i === 0)
            html += item(o.id ?? 'default', `${esc(o.target)}${i === 0 ? ' <span class="commentary-edit-muted">(standaard)</span>' : ''}`, current)
        })
        html += item('los', `${esc(original)} <span class="commentary-edit-muted">(Los ongewijzigd)</span>`, chosen === 'los')
        if (chosen !== 'default') html += item('default', '<span class="commentary-edit-muted">Terug naar de standaard</span>', false)
        this.#open(word, html)
        this.pop.querySelectorAll('[data-choice]').forEach((b) => b.addEventListener('click', () => this.#choose(word, b.dataset.choice)))
    }

    async #choose(word, choice) {
        const section = word.closest('[data-segment-id]')
        const column = word.closest('.commentary-nl-modern')
        const answer = await this.#post(this.choiceUrlValue, {
            segment: Number(section.dataset.segmentId), key: word.dataset.key,
            occurrence: Number(word.dataset.occurrence), choice, _token: this.choiceTokenValue,
        })
        if (!answer) return
        column.innerHTML = answer.html
        this.#close()
        this.#showFlags(section)
    }

    // ── Flags ────────────────────────────────────────────────────────────────

    // a ⚑ button next to selected text in one of the columns
    #offerFlag() {
        this.flagButton?.remove()
        this.flagButton = null
        const selection = window.getSelection()
        if (selection.isCollapsed || !selection.rangeCount) return
        const range = selection.getRangeAt(0)
        const start = range.startContainer.parentElement?.closest('[data-flag-field]')
        const end = range.endContainer.parentElement?.closest('[data-flag-field]')
        if (!start || start !== end || !this.element.contains(start)) return
        const spot = this.#spotOf(start, range)
        if (!spot) return
        const rect = range.getBoundingClientRect()
        const button = document.createElement('button')
        button.type = 'button'
        button.className = 'text-flag-new'
        button.textContent = '⚑ Flag'
        button.title = 'Een probleem in deze tekst aangeven'
        button.style.top = `${rect.bottom + window.scrollY + 4}px`
        button.style.left = `${Math.min(rect.right + window.scrollX, window.scrollX + document.documentElement.clientWidth - 90)}px`
        button.addEventListener('click', () => this.#newFlag(start, spot, button))
        document.body.appendChild(button)
        this.flagButton = button
    }

    #newFlag(column, spot, anchor) {
        const categories = Object.entries(this.categoriesValue)
            .map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('')
        this.#open(anchor, `
            <div class="commentary-edit-title">Flag plaatsen</div>
            <div class="commentary-edit-snippet">…${esc(spot.context_before.slice(-20))}<mark>${esc(spot.snippet)}</mark>${esc(spot.context_after.slice(0, 20))}…</div>
            <label>Probleem <select name="category">${categories}</select></label>
            <label>Notitie <textarea name="note" rows="2" placeholder="bijv. komma te veel"></textarea></label>
            <div class="commentary-edit-actions">
                <button type="button" class="btn btn-sm btn-primary" data-act="save">Opslaan</button>
                <button type="button" class="btn btn-sm" data-act="cancel">Annuleren</button>
            </div>`)
        this.flagButton?.remove()
        this.flagButton = null
        this.pop.querySelector('select').focus()
        this.pop.querySelector('[data-act=cancel]').addEventListener('click', () => this.#close())
        this.pop.querySelector('[data-act=save]').addEventListener('click', async () => {
            const section = column.closest('[data-segment-id]')
            const flag = await this.#post(this.flagsUrlValue, {
                segment: Number(section.dataset.segmentId), field: column.dataset.flagField, ...spot,
                category: this.pop.querySelector('select').value, note: this.pop.querySelector('textarea').value,
                _token: this.flagTokenValue,
            })
            if (!flag) return
            this.#setFlags(section, [...this.#flagsOf(section), flag])
            window.getSelection().removeAllRanges()
            this.#close()
        })
    }

    #openFlag(icon) {
        const section = icon.closest('[data-segment-id]')
        const flag = this.#flagsOf(section).find((f) => f.id === Number(icon.dataset.flagId))
        if (!flag) return
        const categories = Object.entries(this.categoriesValue)
            .map(([k, v]) => `<option value="${esc(k)}"${k === flag.category ? ' selected' : ''}>${esc(v)}</option>`).join('')
        this.#open(icon, `
            <div class="commentary-edit-title">Flag: „${esc(flag.snippet)}”</div>
            <label>Probleem <select name="category">${categories}</select></label>
            <label>Notitie <textarea name="note" rows="2">${esc(flag.note ?? '')}</textarea></label>
            <label class="commentary-edit-check"><input type="checkbox" name="resolved"${flag.status === 'resolved' ? ' checked' : ''}> Opgelost</label>
            <div class="commentary-edit-muted">${esc(flag.created_by ?? '')} · ${esc((flag.created_at ?? '').slice(0, 10))}</div>
            <div class="commentary-edit-actions">
                <button type="button" class="btn btn-sm btn-primary" data-act="save">Opslaan</button>
                <button type="button" class="btn btn-sm" data-act="delete">Verwijderen</button>
            </div>`)
        this.pop.querySelector('[data-act=save]').addEventListener('click', async () => {
            const updated = await this.#post(`${this.flagsUrlValue}/${flag.id}`, {
                category: this.pop.querySelector('select').value, note: this.pop.querySelector('textarea').value,
                status: this.pop.querySelector('[name=resolved]').checked ? 'resolved' : 'open', _token: this.flagTokenValue,
            })
            if (!updated) return
            this.#setFlags(section, this.#flagsOf(section).map((f) => (f.id === flag.id ? updated : f)))
            this.#close()
        })
        this.pop.querySelector('[data-act=delete]').addEventListener('click', async () => {
            if (!(await confirmDialog(`De flag op „${flag.snippet}” verwijderen?`))) return
            const done = await this.#post(`${this.flagsUrlValue}/${flag.id}/verwijderen`, { _token: this.flagTokenValue })
            if (!done) return
            this.#setFlags(section, this.#flagsOf(section).filter((f) => f.id !== flag.id))
            this.#close()
        })
    }

    #flagsOf(section) {
        try { return JSON.parse(section.dataset.flags || '[]') } catch { return [] }
    }

    #setFlags(section, flags) {
        section.dataset.flags = JSON.stringify(flags)
        this.#showFlags(section)
    }

    // the flags of a segment as highlights with a ⚑, in their columns
    #showFlags(section) {
        section.querySelectorAll('.text-flag-icon').forEach((i) => i.remove())
        section.querySelectorAll('mark.text-flag').forEach((m) => {
            const parent = m.parentNode
            m.replaceWith(...m.childNodes)
            parent.normalize()
        })
        for (const flag of this.#flagsOf(section)) {
            const column = section.querySelector(`[data-flag-field="${flag.field}"]`)
            if (!column) continue
            const model = textModel(column)
            const at = locate(model.text, flag)
            if (at === null) continue // the text changed: see the flags overview
            highlight(model, at, at + flag.snippet.length, flag)
        }
    }

    // where a selection is in the column's text: the flagged piece + context
    #spotOf(column, range) {
        const model = textModel(column)
        let start = offsetIn(model, column, range.startContainer, range.startOffset)
        let end = offsetIn(model, column, range.endContainer, range.endOffset)
        while (start < end && /\s/.test(model.text[start])) start++
        while (end > start && /\s/.test(model.text[end - 1])) end--
        if (end <= start) return null
        return {
            snippet: model.text.slice(start, end),
            context_before: model.text.slice(Math.max(0, start - CONTEXT), start),
            context_after: model.text.slice(end, end + CONTEXT),
        }
    }

    // ── Popover ──────────────────────────────────────────────────────────────

    #open(anchor, html) {
        this.#close()
        const rect = anchor.getBoundingClientRect()
        this.pop = document.createElement('div')
        this.pop.className = 'commentary-edit-pop'
        this.pop.innerHTML = html
        this.pop.style.top = `${rect.bottom + window.scrollY + 6}px`
        this.pop.style.left = `${Math.max(window.scrollX + 8, Math.min(rect.left + window.scrollX,
            window.scrollX + document.documentElement.clientWidth - 300))}px`
        document.body.appendChild(this.pop)
    }

    #close() {
        this.pop?.remove()
        this.pop = null
        this.flagButton?.remove()
        this.flagButton = null
    }

    async #post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
            })
            const data = await response.json()
            if (!response.ok) throw new Error(data.error || 'Opslaan mislukt.')
            return data
        } catch (e) {
            window.alert(e.message || 'Opslaan mislukt.')
            return null
        }
    }
}

// ── Text as the reader sees it ────────────────────────────────────────────────

function textModel(column) {
    const nodes = []
    let text = ''
    const walker = document.createTreeWalker(column, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => (n.parentElement.closest(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
    })
    let node
    while ((node = walker.nextNode())) {
        nodes.push({ node, start: text.length })
        text += node.data
    }
    return { text, nodes }
}

// the offset in the model's text of a DOM position (container, offset)
function offsetIn(model, column, container, offset) {
    const before = document.createRange()
    before.setStart(column, 0)
    before.setEnd(container, offset)
    let at = 0
    for (const { node } of model.nodes) {
        if (node === container) return at + offset
        if (!before.intersectsNode(node)) break
        at += node.data.length
    }
    return at
}

// where a flag's text is: the occurrence whose surroundings match best
function locate(text, flag) {
    let best = null
    let bestScore = -1
    for (let i = text.indexOf(flag.snippet); i !== -1; i = text.indexOf(flag.snippet, i + 1)) {
        const score = common(text.slice(0, i), flag.context_before ?? '', true)
            + common(text.slice(i + flag.snippet.length), flag.context_after ?? '', false)
        if (score > bestScore) {
            best = i
            bestScore = score
        }
    }
    return best
}

// how many characters two strings share at their end (fromEnd) or start
function common(a, b, fromEnd) {
    let n = 0
    const max = Math.min(a.length, b.length)
    while (n < max && (fromEnd ? a[a.length - 1 - n] === b[b.length - 1 - n] : a[n] === b[n])) n++
    return n
}

// wrap text [start, end) of the model in highlights, a ⚑ after the last
function highlight(model, start, end, flag) {
    let last = null
    for (const { node, start: at } of model.nodes) {
        const from = Math.max(start, at)
        const to = Math.min(end, at + node.data.length)
        if (from >= to) continue
        let piece = node
        if (from > at) piece = piece.splitText(from - at)
        if (to < at + node.data.length) piece.splitText(to - from)
        const mark = document.createElement('mark')
        mark.className = `text-flag text-flag-${flag.status}`
        mark.dataset.flagId = flag.id
        piece.replaceWith(mark)
        mark.appendChild(piece)
        last = mark
    }
    if (!last) return
    const icon = document.createElement('button')
    icon.type = 'button'
    icon.className = `text-flag-icon text-flag-${flag.status}`
    icon.dataset.flagId = flag.id
    icon.title = flag.note ? `${flag.category}: ${flag.note}` : flag.category
    icon.textContent = '⚑'
    last.after(icon)
}

function esc(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])
}
