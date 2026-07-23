import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Drag-based sentence-alignment editor (see InstitutioAlignmentController).
 *
 * Both panels are flat sibling lists under their target element -- no
 * nested per-row wrapper divs. Row membership is purely positional:
 *   - Dutch panel: word, word, boundary, word, boundary, word, word, ...
 *     Everything between two .alignment-boundary elements (or list edges)
 *     belongs to one row. Dragging a boundary just reorders it among its
 *     two neighbouring rows' word siblings -- moving it IS reassigning
 *     word-to-row membership, no separate bookkeeping needed.
 *   - Latin panel: sentence, gap, sentence, gap, sentence, ... where each
 *     gap is either an active row boundary (⌃, click to merge) or a plain
 *     splittable gap (✂, click to split). Latin sentences themselves are
 *     fixed; only which gaps are "active" changes.
 *
 * Row count and row order always match between the two panels because
 * toggleGap() edits both in lockstep (by counting how many active Latin
 * boundaries precede the clicked gap, then inserting/removing the Dutch
 * boundary at that same row index).
 */
export default class extends Controller {
    static targets = ['laPanel', 'nlPanel', 'word', 'boundary', 'status', 'preview']
    static values  = { saveUrl: String }

    connect() {
        this.#recolor()
        this.#renderPreview()
    }

    // ── Latin-side gap toggling (split / merge) ───────────────────────────────

    toggleGap(event) {
        const gap = event.currentTarget
        const rowIndex = this.#countActiveBoundariesBefore(gap)

        if (gap.classList.contains('is-boundary')) {
            this.#mergeAt(gap, rowIndex)
        } else {
            this.#splitAt(gap, rowIndex)
        }
        this.#recolor()
        this.#renderPreview()
    }

    #countActiveBoundariesBefore(gapEl) {
        let count = 0
        for (const child of this.laPanelTarget.children) {
            if (child === gapEl) break
            if (child.matches('.alignment-la-gap.is-boundary')) count++
        }
        return count
    }

    #dutchBoundaries() {
        return [...this.nlPanelTarget.children].filter(c => c.matches('.alignment-boundary'))
    }

    #mergeAt(gap, rowIndex) {
        const boundaries = this.#dutchBoundaries()
        boundaries[rowIndex]?.remove()
        gap.classList.remove('is-boundary')
        gap.textContent = '✂'
        gap.title = 'Splits hier'
    }

    #splitAt(gap, rowIndex) {
        const boundaries = this.#dutchBoundaries()
        const insertBefore = boundaries[rowIndex] ?? null // null => last row, append at end

        const newBoundary = document.createElement('span')
        newBoundary.className = 'alignment-boundary'
        newBoundary.title = 'Sleep om grens te verplaatsen'
        newBoundary.setAttribute('data-alignment-editor-target', 'boundary')
        newBoundary.setAttribute('data-action', 'pointerdown->alignment-editor#startDrag')

        if (insertBefore) {
            this.nlPanelTarget.insertBefore(newBoundary, insertBefore)
        } else {
            this.nlPanelTarget.appendChild(newBoundary)
        }

        gap.classList.add('is-boundary')
        gap.textContent = '⌃'
        gap.title = 'Voeg samen met volgende rij'
    }

    // ── Dutch-side dragging ────────────────────────────────────────────────────

    startDrag(event) {
        event.preventDefault()
        const boundaryEl = event.currentTarget
        const pointerId = event.pointerId

        const words = [
            ...this.#wordsBeside(boundaryEl, -1),
            ...this.#wordsBeside(boundaryEl, 1),
        ]
        if (words.length === 0) return

        boundaryEl.classList.add('is-dragging')
        boundaryEl.setPointerCapture(pointerId)

        const onMove = (e) => this.#dragMove(e, boundaryEl, words)
        const onUp = (e) => {
            boundaryEl.classList.remove('is-dragging')
            try { boundaryEl.releasePointerCapture(pointerId) } catch { /* already released */ }
            window.removeEventListener('pointermove', onMove)
            window.removeEventListener('pointerup', onUp)
            this.#recolor()
            this.#renderPreview()
        }
        window.addEventListener('pointermove', onMove)
        window.addEventListener('pointerup', onUp)
    }

    // Words belonging to the row immediately before (-1) or after (+1) a
    // boundary -- stops at the next boundary in that direction, so a drag
    // can never reach into a third row.
    #wordsBeside(boundaryEl, direction) {
        const words = []
        let node = direction === -1 ? boundaryEl.previousElementSibling : boundaryEl.nextElementSibling
        while (node && !node.matches('.alignment-boundary')) {
            if (node.matches('.alignment-word')) {
                direction === -1 ? words.unshift(node) : words.push(node)
            }
            node = direction === -1 ? node.previousElementSibling : node.nextElementSibling
        }
        return words
    }

    // The Dutch panel is flowing paragraph text, so a row's words can span
    // several visual lines. Matching by clientX alone (ignoring which line
    // the cursor is actually on) picks up words from unrelated lines by
    // horizontal coincidence -- confirmed: it made the boundary jump to
    // word 0 whenever the cursor moved to a different line, since a
    // wrapped line's first word sits back at the container's left edge.
    // Snap to the visual line closest to the cursor's Y first, then match
    // horizontally only within that line.
    #dragMove(event, boundaryEl, words) {
        if (words.length === 0) return

        const lines = new Map()
        for (const w of words) {
            const top = Math.round(w.getBoundingClientRect().top)
            if (!lines.has(top)) lines.set(top, [])
            lines.get(top).push(w)
        }

        let closestTop = null
        let bestDy = Infinity
        for (const top of lines.keys()) {
            const dy = Math.abs(event.clientY - top)
            if (dy < bestDy) { bestDy = dy; closestTop = top }
        }
        const lineWords = lines.get(closestTop)

        let target = null
        for (const w of lineWords) {
            const rect = w.getBoundingClientRect()
            if (event.clientX < rect.left + rect.width / 2) {
                target = w
                break
            }
        }

        let moved = false
        if (target) {
            if (boundaryEl.nextElementSibling !== target) {
                this.nlPanelTarget.insertBefore(boundaryEl, target)
                moved = true
            }
        } else {
            const last = lineWords[lineWords.length - 1]
            if (last.nextElementSibling !== boundaryEl) {
                last.after(boundaryEl)
                moved = true
            }
        }

        // Only re-render when the boundary actually moved -- dragMove fires
        // on every pointermove, most of which don't cross a word gap.
        if (moved) {
            this.#recolor()
            this.#renderPreview()
        }
    }

    // ── Visual row grouping ─────────────────────────────────────────────────────

    #recolor() {
        let rowIndex = 0
        for (const child of this.nlPanelTarget.children) {
            if (child.matches('.alignment-boundary')) {
                rowIndex++
                continue
            }
            child.classList.toggle('row-odd', rowIndex % 2 === 1)
        }
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    async save() {
        const alignment = this.#currentAlignment()
        if (alignment.some(r => r.words.length === 0)) {
            this.#setStatus('Elke rij moet minstens één woord bevatten — sleep woorden erin voordat je opslaat.')
            return
        }

        this.#setStatus('Bezig met opslaan…')
        try {
            const resp = await fetch(this.saveUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({
                    rows: alignment.map(({ la_start, words }) => ({ la_start, words })),
                }),
            })
            const data = await resp.json()
            if (!data.success) throw new Error(data.error || 'Opslaan mislukt.')

            this.#setStatus(
                data.alignment_dropped
                    ? '✓ Opgeslagen. Let op: de woord-uitlijning (fase 4) is verwijderd en moet opnieuw via align_segments.py.'
                    : '✓ Opgeslagen.'
            )
        } catch (err) {
            this.#setStatus(`Fout bij opslaan: ${err.message}`)
        }
    }

    // Reads the current DOM state of both panels into one row list --
    // {la_start, la_text, words}[] -- used both to build the save payload
    // and to render the live preview, so the two can never drift apart.
    #currentAlignment() {
        const dutchGroups = [[]]
        for (const child of this.nlPanelTarget.children) {
            if (child.matches('.alignment-boundary')) {
                dutchGroups.push([])
            } else if (child.matches('.alignment-word')) {
                dutchGroups[dutchGroups.length - 1].push(child.textContent)
            }
        }

        const laRows = [{ la_start: null, sentences: [] }]
        for (const child of this.laPanelTarget.children) {
            if (child.matches('.alignment-la-sentence')) {
                const current = laRows[laRows.length - 1]
                if (current.la_start === null) current.la_start = parseInt(child.dataset.offset, 10)
                current.sentences.push(child.textContent)
            } else if (child.matches('.alignment-la-gap.is-boundary')) {
                laRows.push({ la_start: parseInt(child.dataset.offset, 10), sentences: [] })
            }
        }

        return laRows.map((lr, i) => ({
            la_start: lr.la_start,
            la_text:  lr.sentences.join(' '),
            words:    dutchGroups[i] ?? [],
        }))
    }

    // ── Live preview (mirrors chapter.html.twig's sentence-row markup) ─────────

    #renderPreview() {
        const alignment = this.#currentAlignment()
        this.previewTarget.replaceChildren(
            ...alignment.map(row => {
                const rowEl = document.createElement('div')
                rowEl.className = 'institutio-sentence-row'

                const laEl = document.createElement('p')
                laEl.className = 'institutio-sentence-la'
                laEl.textContent = row.la_text

                const nlEl = document.createElement('p')
                nlEl.className = 'institutio-sentence-nl'
                nlEl.textContent = row.words.join(' ') || '(leeg)'

                rowEl.append(laEl, nlEl)
                return rowEl
            })
        )
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) this.statusTarget.textContent = msg
    }
}
