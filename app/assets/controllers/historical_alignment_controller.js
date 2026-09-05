// assets/controllers/historical_alignment_controller.js
//
// 4-column historical-alignment review UI (plan sectie 6): draws an SVG
// bezier-line overlay between linked word chips across SV1657/SV/SVGBS/HSV,
// click-word-then-word-in-another-column to link, click-a-line to unlink,
// hover to highlight the whole chain, arrow keys + space/Enter to navigate
// and (dis)connect by keyboard, "A" to approve the verse.

import { Controller } from '@hotwired/stimulus'

const SVG_NS = 'http://www.w3.org/2000/svg'
const TOAST_MS = 5000

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export default class extends Controller {
    static targets = ['columns', 'svg', 'scoreDisplay', 'gapWarning', 'toast', 'word']
    static values = {
        linkUrl:      String,
        unlinkUrl:    String,
        libraryAddUrl: String,
        approveUrl:   String,
        recomputeUrl: String,
        usfm:         String,
        chapter:      String,
        verse:        String,
        nextUrl:      String,
        wordIds:      Array,
        links:        Array,
        topology:     String,
    }

    #links = []
    #adjacency = new Map()
    #pending = null
    #toastTimer = null
    #onResize = null

    connect() {
        this.#links = [...this.linksValue]
        this.#buildAdjacency()
        this.#renderLines()
        this.#updateGapWarning()

        this.#onResize = this.#debounce(() => this.#renderLines(), 150)
        window.addEventListener('resize', this.#onResize)
        window.addEventListener('load', this.#onResize)

        this.svgTarget.addEventListener('click', (e) => {
            const path = e.target.closest('[data-link-id]')
            if (path) this.#unlink(path.dataset.wordA, path.dataset.wordB)
        })
    }

    disconnect() {
        window.removeEventListener('resize', this.#onResize)
        window.removeEventListener('load', this.#onResize)
        clearTimeout(this.#toastTimer)
    }

    // ── Word click: select A, then click B in another column to link ────────

    selectWord(event) {
        if (this.#isReadonly()) return
        const el = event.currentTarget
        const wordId = el.dataset.wordId
        const code = el.dataset.code

        if (this.#pending && this.#pending.wordId === wordId) {
            this.#clearPending()
            return
        }
        if (this.#pending && this.#pending.code !== code) {
            const fromId = this.#pending.wordId
            this.#clearPending()
            this.#link(fromId, wordId)
            return
        }
        this.#setPending(wordId, code, el)
    }

    // ── Hover: highlight the whole chain across all four columns ───────────

    hoverWord(event) {
        this.#highlightChain(event.currentTarget.dataset.wordId)
    }

    unhoverWord() {
        this.#clearHighlight()
    }

    // ── Keyboard navigation ──────────────────────────────────────────────────

    onKeydown(event) {
        switch (event.key) {
            case 'ArrowRight':
            case 'ArrowLeft':
            case 'ArrowUp':
            case 'ArrowDown':
                event.preventDefault()
                this.#moveFocus(event.currentTarget, event.key)
                break
            case ' ':
            case 'Enter':
                event.preventDefault()
                this.selectWord(event)
                break
            case 'a':
            case 'A':
                event.preventDefault()
                this.approve()
                break
        }
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    async approve() {
        if (this.#isReadonly()) return
        const data = await this.#postJson(this.approveUrlValue, { word_ids: this.wordIdsValue })
        if (!data?.success) {
            this.#showToast('Goedkeuren mislukt.')
            return
        }
        this.#showToast('Vers goedgekeurd.')
        setTimeout(() => this.#goToNextOrReload(), 400)
    }

    async recompute() {
        if (this.#isReadonly()) return
        const data = await this.#postJson(this.recomputeUrlValue, {
            scope_type: 'verse',
            usfm:       this.usfmValue,
            chapter:    this.chapterValue,
            verse:      this.verseValue,
            topology:   this.topologyValue,
        })
        if (!data?.success) {
            this.#showToast('Herberekenen mislukt.')
            return
        }
        this.#showToast('Herberekend — pagina wordt herladen…')
        setTimeout(() => window.location.reload(), 600)
    }

    // ── Link / unlink with optimistic UI + undo toast ───────────────────────

    async #link(wordAId, wordBId) {
        // A word can legitimately have more than one link (1:n/n:1), so an
        // existing link is never auto-removed here -- but if this word had
        // some OTHER link before this one, it's often a stale auto-suggestion
        // the user meant to replace. Flag it instead of silently leaving it.
        const hadOtherLinkBefore = this.#links.some(
            (l) => !this.#sameEdge(l, wordAId, wordBId)
                && (String(l.word_a_id) === String(wordAId) || String(l.word_b_id) === String(wordAId)
                 || String(l.word_a_id) === String(wordBId) || String(l.word_b_id) === String(wordBId))
        )

        const optimistic = { id: `tmp-${wordAId}-${wordBId}`, word_a_id: wordAId, word_b_id: wordBId, method: 'manual', score: 1.0 }
        this.#links.push(optimistic)
        this.#refresh()

        const data = await this.#postJson(this.linkUrlValue, { word_a_id: wordAId, word_b_id: wordBId })
        if (!data?.success) {
            this.#links = this.#links.filter((l) => l !== optimistic)
            this.#refresh()
            this.#showToast(`Kon niet koppelen: ${data?.error ?? 'onbekende fout'}`)
            return
        }
        const message = hadOtherLinkBefore
            ? 'Koppeling opgeslagen. Een van beide woorden had al een andere lijn — klik die lijn aan om ze te verwijderen als hij niet meer klopt.'
            : 'Koppeling opgeslagen.'
        this.#showToast(message, [
            { label: 'Ongedaan maken', onClick: () => this.#unlink(wordAId, wordBId) },
            { label: '+ Lexicon', onClick: () => this.#addToLibrary(wordAId, wordBId, 'lexicon') },
            { label: '+ Synoniem', onClick: () => this.#addToLibrary(wordAId, wordBId, 'synonym') },
        ])
    }

    async #unlink(wordAId, wordBId) {
        const removed = this.#links.find((l) => this.#sameEdge(l, wordAId, wordBId))
        if (!removed) return
        this.#links = this.#links.filter((l) => l !== removed)
        this.#refresh()

        const data = await this.#postJson(this.unlinkUrlValue, { word_a_id: wordAId, word_b_id: wordBId })
        if (!data?.success) {
            this.#links.push(removed)
            this.#refresh()
            this.#showToast('Kon koppeling niet verwijderen.')
            return
        }
        this.#showToast('Koppeling verwijderd.', [{ label: 'Ongedaan maken', onClick: () => this.#link(wordAId, wordBId) }])
    }

    // ── Promote a manual link into a lexicon/synonym library entry ─────────

    async #addToLibrary(wordAId, wordBId, kind) {
        const data = await this.#postJson(this.libraryAddUrlValue, { word_a_id: wordAId, word_b_id: wordBId, kind })
        if (!data) {
            this.#showToast('Kon niet toevoegen: netwerkfout.')
            return
        }
        this.#showToast(data.message ?? (data.success ? 'Toegevoegd.' : 'Kon niet toevoegen.'))
    }

    #sameEdge(link, a, b) {
        const la = String(link.word_a_id)
        const lb = String(link.word_b_id)
        return (la === String(a) && lb === String(b)) || (la === String(b) && lb === String(a))
    }

    #refresh() {
        this.#buildAdjacency()
        this.#renderLines()
        this.#updateGapWarning()
    }

    // ── SVG line rendering ────────────────────────────────────────────────────

    #renderLines() {
        const svg = this.svgTarget
        const containerRect = this.columnsTarget.getBoundingClientRect()
        svg.setAttribute('width', String(this.columnsTarget.scrollWidth))
        svg.setAttribute('height', String(this.columnsTarget.scrollHeight))
        while (svg.firstChild) svg.removeChild(svg.firstChild)

        for (const link of this.#links) {
            const elA = this.#wordEl(link.word_a_id)
            const elB = this.#wordEl(link.word_b_id)
            if (!elA || !elB) continue

            const rectA = elA.getBoundingClientRect()
            const rectB = elB.getBoundingClientRect()
            const [left, right] = rectA.left <= rectB.left ? [rectA, rectB] : [rectB, rectA]

            const x1 = left.right - containerRect.left
            const y1 = left.top + left.height / 2 - containerRect.top
            const x2 = right.left - containerRect.left
            const y2 = right.top + right.height / 2 - containerRect.top
            const dx = Math.max((x2 - x1) * 0.4, 24)
            const d = `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`

            const path = document.createElementNS(SVG_NS, 'path')
            path.setAttribute('d', d)
            path.setAttribute('class', `hist-align-line method-${link.method}`)
            path.dataset.linkId = link.id
            path.dataset.wordA = link.word_a_id
            path.dataset.wordB = link.word_b_id

            const hit = document.createElementNS(SVG_NS, 'path')
            hit.setAttribute('d', d)
            hit.setAttribute('class', 'hist-align-line-hit')
            hit.dataset.linkId = link.id
            hit.dataset.wordA = link.word_a_id
            hit.dataset.wordB = link.word_b_id

            svg.appendChild(path)
            svg.appendChild(hit)
        }
    }

    // ── Hover-chain highlighting (transitive closure over the link graph) ──

    #highlightChain(startWordId) {
        const visited = new Set()
        const queue = [String(startWordId)]
        while (queue.length) {
            const id = queue.shift()
            if (visited.has(id)) continue
            visited.add(id)
            for (const edge of this.#adjacency.get(id) ?? []) {
                if (!visited.has(String(edge.other))) queue.push(String(edge.other))
            }
        }

        this.wordTargets.forEach((el) => {
            el.classList.toggle('hist-align-word-chain', visited.has(el.dataset.wordId))
        })
        this.svgTarget.querySelectorAll('[data-link-id]').forEach((el) => {
            el.classList.toggle('hist-align-line-chain', visited.has(el.dataset.wordA) && visited.has(el.dataset.wordB))
        })
        this.element.classList.add('hist-align-hovering')
    }

    #clearHighlight() {
        this.element.classList.remove('hist-align-hovering')
        this.wordTargets.forEach((el) => el.classList.remove('hist-align-word-chain'))
        this.svgTarget.querySelectorAll('.hist-align-line-chain').forEach((el) => el.classList.remove('hist-align-line-chain'))
    }

    // ── Keyboard focus movement ──────────────────────────────────────────────

    // Words are laid out in a table now (one row per pivot word, matching
    // words from other columns placed in that same row -- see
    // HistoricalAlignmentRowBuilder), not in per-column DOM containers, so
    // navigation goes by data-code/data-row rather than DOM ancestry.
    #moveFocus(fromEl, key) {
        const code = fromEl.dataset.code
        const wordsInCode = this.wordTargets.filter((el) => el.dataset.code === code)

        if (key === 'ArrowRight' || key === 'ArrowLeft') {
            const idx = wordsInCode.indexOf(fromEl)
            const nextIdx = Math.max(0, Math.min(idx + (key === 'ArrowRight' ? 1 : -1), wordsInCode.length - 1))
            wordsInCode[nextIdx]?.focus()
            return
        }

        const row = parseInt(fromEl.dataset.row, 10)
        const candidates = wordsInCode
            .map((el) => ({ el, row: parseInt(el.dataset.row, 10) }))
            .filter((c) => (key === 'ArrowDown' ? c.row > row : c.row < row))
            .sort((a, b) => (key === 'ArrowDown' ? a.row - b.row : b.row - a.row))
        candidates[0]?.el.focus()
    }

    // ── Pending selection state ──────────────────────────────────────────────

    #setPending(wordId, code, el) {
        this.#clearPendingVisual()
        this.#pending = { wordId, code }
        el.classList.add('hist-align-word-pending')
    }

    #clearPending() {
        this.#clearPendingVisual()
        this.#pending = null
    }

    #clearPendingVisual() {
        this.wordTargets.forEach((el) => el.classList.remove('hist-align-word-pending'))
    }

    // ── Gap warning ──────────────────────────────────────────────────────────

    #updateGapWarning() {
        const hasGap = this.wordTargets.some((el) => {
            if (el.classList.contains('word-note')) return false
            return !this.#adjacency.has(el.dataset.wordId)
        })
        this.gapWarningTarget.hidden = !hasGap
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    #buildAdjacency() {
        this.#adjacency = new Map()
        for (const link of this.#links) {
            const a = String(link.word_a_id)
            const b = String(link.word_b_id)
            if (!this.#adjacency.has(a)) this.#adjacency.set(a, [])
            if (!this.#adjacency.has(b)) this.#adjacency.set(b, [])
            this.#adjacency.get(a).push({ other: b, method: link.method, score: link.score })
            this.#adjacency.get(b).push({ other: a, method: link.method, score: link.score })
        }
    }

    #wordEl(id) {
        return this.element.querySelector(`.hist-align-word[data-word-id="${id}"]`)
    }

    #isReadonly() {
        return this.element.classList.contains('review-lock-readonly')
    }

    #goToNextOrReload() {
        if (this.nextUrlValue) {
            if (window.Turbo) {
                window.Turbo.visit(this.nextUrlValue)
            } else {
                window.location.href = this.nextUrlValue
            }
        } else {
            window.location.reload()
        }
    }

    // `buttons` is an array of { label, onClick }, rendered left to right
    // after the message (used for the undo button, and for the "Lexicon" /
    // "Synoniem" library-add prompt shown after creating a manual link).
    #showToast(message, buttons = []) {
        clearTimeout(this.#toastTimer)
        const el = this.toastTarget
        el.textContent = `${message} `
        for (const { label, onClick } of buttons) {
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.className = 'hist-align-toast-btn'
            btn.textContent = label
            btn.addEventListener('click', () => {
                onClick()
                this.#hideToast()
            })
            el.appendChild(btn)
        }
        el.hidden = false
        this.#toastTimer = setTimeout(() => this.#hideToast(), TOAST_MS)
    }

    #hideToast() {
        this.toastTarget.hidden = true
        this.toastTarget.textContent = ''
    }

    async #postJson(url, body) {
        try {
            const resp = await fetch(url, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body:    JSON.stringify(body),
            })
            return await resp.json()
        } catch {
            return null
        }
    }

    #debounce(fn, ms) {
        let t
        return (...args) => {
            clearTimeout(t)
            t = setTimeout(() => fn(...args), ms)
        }
    }
}
