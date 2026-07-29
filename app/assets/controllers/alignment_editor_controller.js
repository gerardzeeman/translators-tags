import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

/**
 * Drag-based sentence-alignment editor (see InstitutioAlignmentController).
 *
 * The Latin panel and every translation panel (own "llm" translation, plus
 * Weijenberg's 1865 translation when the segment has one) are each a flat
 * sibling list -- no nested per-row wrapper divs. Row membership is purely
 * positional:
 *   - Translation panels: word, word, boundary, word, boundary, word, ...
 *     Everything between two .alignment-boundary elements (or list edges)
 *     belongs to one row. Dragging a boundary reorders it among its two
 *     neighbouring rows' word siblings *within that panel only* -- each
 *     translation's word-to-row split is independent of the others.
 *   - Latin panel: sentence, gap, sentence, gap, ... where each gap is
 *     either an active row boundary (⌃, click to merge) or a plain
 *     splittable gap (✂, click to split).
 *
 * Row COUNT and boundary positions (which Latin sentences make up row N)
 * are shared across every translation panel: toggleGap() inserts/removes a
 * boundary at the same row index in every panel at once, since "row N"
 * only means anything if every column agrees on where it starts and ends.
 * Saving still writes each translation's alignment independently (own
 * translation_id, own sentence_alignment rows) -- only the row
 * *boundaries* are locked together, not the words assigned to them.
 *
 * Optional heading row: when the segment has one (chapter/section title,
 * or the front-matter salutation), the Latin panel gets a fixed leading
 * `.alignment-heading-la` block plus a permanent separator -- unlike the
 * la-sentence gaps, this boundary has no toggle control and can never be
 * removed, only repositioned (drag/click-to-assign), since the heading is
 * a structurally separate field, never part of text_la. Every translation
 * panel then has one extra row-0 (its heading translation) ahead of the
 * normal per-sentence rows, so row indices coming from Latin-gap counting
 * need a +1 offset (see #headingOffset) when used against a translation
 * panel's boundary list.
 *
 * Row collapsing: Weijenberg tends to run noticeably longer per row than
 * the own translation, so the rows before each panel's own "open" row --
 * see #activeRowIndex -- are hidden behind one summary banner. The open
 * row is deliberately NOT just "the first empty one": while progressively
 * carving rows out via drag/click-to-assign, it holds the entire not-yet-
 * distributed remainder (often the longest, non-empty row of all), so
 * collapsing on plain emptiness would hide exactly the text still waiting
 * to be aligned. Purely a display affordance (an `.is-collapsed-row` class
 * toggled on the existing elements, a CSS rule that hides it, and a
 * `.show-collapsed` override class), so it never touches the row/boundary
 * DOM structure the rest of this controller depends on. Recomputed after
 * every mutation (see #refresh); the boundary that *closes* the last
 * collapsed row is hidden along with it, so adjusting it requires
 * expanding first via the banner.
 */
export default class extends Controller {
    static targets = ['laPanel', 'translationPanel', 'status', 'preview', 'columns', 'collapseBanner']
    static values  = { saveUrl: String }

    #lastCollapsedCount = 0

    connect() {
        this.#recolorAll()
        this.#refresh()
    }

    // Re-renders everything derived from the current DOM state -- called
    // after every action that can change row contents or boundaries.
    #refresh() {
        this.#renderPreview()
        this.#applyRowCollapsing()
    }

    toggleCollapsed() {
        this.columnsTarget.classList.toggle('show-collapsed')
        this.#updateCollapseBanner()
    }

    // ── Latin-side gap toggling (split / merge) -- affects every translation panel ──

    toggleGap(event) {
        const gap = event.currentTarget
        const rowIndex = this.#countActiveBoundariesBefore(gap) + this.#headingOffset()

        if (gap.classList.contains('is-boundary')) {
            this.#mergeAt(gap, rowIndex)
        } else {
            this.#splitAt(gap, rowIndex)
        }
        this.#recolorAll()
        this.#refresh()
    }

    #countActiveBoundariesBefore(gapEl) {
        let count = 0
        for (const child of this.laPanelTarget.children) {
            if (child === gapEl) break
            if (child.matches('.alignment-la-gap.is-boundary')) count++
        }
        return count
    }

    #boundariesIn(panel) {
        return [...panel.children].filter(c => c.matches('.alignment-boundary'))
    }

    // 1 if this segment has the fixed heading row (an extra row-0 ahead of
    // the per-Latin-sentence rows in every translation panel), else 0.
    #headingOffset() {
        return this.laPanelTarget.querySelector('.alignment-heading-la') ? 1 : 0
    }

    #mergeAt(gap, rowIndex) {
        for (const panel of this.translationPanelTargets) {
            this.#boundariesIn(panel)[rowIndex]?.remove()
        }
        gap.classList.remove('is-boundary')
        gap.textContent = '✂'
        gap.title = 'Splits hier'
    }

    #splitAt(gap, rowIndex) {
        for (const panel of this.translationPanelTargets) {
            const insertBefore = this.#boundariesIn(panel)[rowIndex] ?? null // null => last row, append at end

            const newBoundary = document.createElement('span')
            newBoundary.className = 'alignment-boundary'
            newBoundary.title = 'Sleep om grens te verplaatsen'
            newBoundary.setAttribute('data-action', 'pointerdown->alignment-editor#startDrag')

            if (insertBefore) {
                panel.insertBefore(newBoundary, insertBefore)
            } else {
                panel.appendChild(newBoundary)
            }
        }

        gap.classList.add('is-boundary')
        gap.textContent = '⌃'
        gap.title = 'Voeg samen met volgende rij'
    }

    // ── Click-to-assign (bulk-carving shortcut for a freshly-loaded,
    // still fully unaligned translation, e.g. Weijenberg right after
    // ingest -- all its words sit in row 0 and every boundary is
    // clustered together at the end with nothing between them) ─────────────────

    // Clicking a word moves the *next still-unplaced* boundary in that
    // panel to right after the clicked word -- i.e. it carves the current
    // "everything so far" row into a real row + the remainder. Only the
    // earliest boundary that's part of an unbroken run of empty gaps all
    // the way to the end of the panel is eligible; if the trailing rows
    // aren't (or are no longer) all empty -- because some later row was
    // already manually aligned, via drag or a previous click -- the whole
    // shortcut is disabled rather than guess which boundary to move.
    assignBoundaryHere(event) {
        const wordEl = event.currentTarget
        const panel = wordEl.parentElement
        const boundaries = this.#boundariesIn(panel)

        let nextIdx = null
        for (let i = boundaries.length - 1; i >= 0; i--) {
            const after = boundaries[i].nextElementSibling
            const gapIsEmpty = !after || after.matches('.alignment-boundary')
            if (!gapIsEmpty) break
            nextIdx = i
        }
        if (nextIdx === null) {
            this.#setStatus('Verderop is al handmatig uitgelijnd -- klik-om-te-plaatsen werkt alleen zolang alle resterende rijgrenzen nog onderaan staan.')
            return
        }

        const targetBoundary = boundaries[nextIdx]
        const prevBoundary = nextIdx > 0 ? boundaries[nextIdx - 1] : null

        let inOpenRow = false
        let node = prevBoundary ? prevBoundary.nextElementSibling : panel.firstElementChild
        while (node && node !== targetBoundary) {
            if (node === wordEl) { inOpenRow = true; break }
            node = node.nextElementSibling
        }
        if (!inOpenRow) {
            this.#setStatus('Klik een woord in de openstaande rij (na de laatst geplaatste grens) om de volgende grens daarheen te verplaatsen.')
            return
        }

        if (targetBoundary.previousElementSibling === wordEl) return // already there

        panel.insertBefore(targetBoundary, wordEl.nextElementSibling)
        this.#recolor(panel)
        this.#refresh()
    }

    // ── Translation-panel dragging (independent per panel) ──────────────────────

    startDrag(event) {
        event.preventDefault()
        const boundaryEl = event.currentTarget
        const panel = boundaryEl.parentElement
        const pointerId = event.pointerId

        const words = [
            ...this.#wordsBeside(boundaryEl, -1),
            ...this.#wordsBeside(boundaryEl, 1),
        ]
        if (words.length === 0) return

        boundaryEl.classList.add('is-dragging')
        boundaryEl.setPointerCapture(pointerId)

        const onMove = (e) => this.#dragMove(e, boundaryEl, panel, words)
        const onUp = () => {
            boundaryEl.classList.remove('is-dragging')
            try { boundaryEl.releasePointerCapture(pointerId) } catch { /* already released */ }
            window.removeEventListener('pointermove', onMove)
            window.removeEventListener('pointerup', onUp)
            this.#recolor(panel)
            this.#refresh()
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

    // A translation panel is flowing paragraph text, so a row's words can
    // span several visual lines. Snap to the visual line closest to the
    // cursor's Y first, then match horizontally only within that line (see
    // the multi-line drag fix this was built for: matching by clientX
    // alone made the boundary jump to word 0 whenever the cursor moved to
    // a different line, since a wrapped line's first word sits back at the
    // container's left edge).
    #dragMove(event, boundaryEl, panel, words) {
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
                panel.insertBefore(boundaryEl, target)
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
            this.#recolor(panel)
            this.#refresh()
        }
    }

    // ── Visual row grouping ───────────────────────────────────────────────────

    #recolorAll() {
        for (const panel of this.translationPanelTargets) this.#recolor(panel)
    }

    #recolor(panel) {
        let rowIndex = 0
        for (const child of panel.children) {
            if (child.matches('.alignment-boundary')) {
                rowIndex++
                continue
            }
            child.classList.toggle('row-odd', rowIndex % 2 === 1)
        }
    }

    // ── Row collapsing (hide a completed leading prefix, see class docblock) ───

    // The row currently "open" for this panel -- the one right before the
    // earliest boundary that's part of the still-fully-untouched trailing
    // run (the same notion assignBoundaryHere uses to pick which boundary
    // a word-click may move). While the user is progressively carving rows
    // out via drag/click-to-assign, this open row holds *all* the not-yet-
    // distributed remainder -- non-empty, often the longest row of all,
    // but definitely not "done": collapsing it would hide exactly the text
    // still waiting to be aligned. Falls back to the last row if this
    // panel has no untouched tail left at all (fully split already).
    #activeRowIndex(panel) {
        const boundaries = this.#boundariesIn(panel)
        const totalRows = boundaries.length + 1

        let nextIdx = null
        for (let i = boundaries.length - 1; i >= 0; i--) {
            const after = boundaries[i].nextElementSibling
            const gapIsEmpty = !after || after.matches('.alignment-boundary')
            if (!gapIsEmpty) break
            nextIdx = i
        }
        return nextIdx !== null ? nextIdx : totalRows - 1
    }

    // A row is collapsible only if it's before every panel's own open row
    // -- the least-progressed panel decides, so the row currently being
    // worked on (in any translation) always stays visible, however far
    // the others have already gotten.
    #applyRowCollapsing() {
        const totalRows = this.#laStarts().length
        const earliestActiveRow = Math.min(
            ...this.translationPanelTargets.map(p => this.#activeRowIndex(p))
        )
        const collapsedCount = Math.min(earliestActiveRow, Math.max(totalRows - 1, 0))
        this.#lastCollapsedCount = collapsedCount

        for (const panel of this.translationPanelTargets) {
            this.#collapseTranslationPanel(panel, collapsedCount)
        }
        this.#collapseLaPanel(collapsedCount)
        this.#updateCollapseBanner()
    }

    #collapseTranslationPanel(panel, collapsedCount) {
        let rowIndex = 0
        for (const child of panel.children) {
            if (child.matches('.alignment-boundary')) {
                child.classList.toggle('is-collapsed-row', rowIndex < collapsedCount)
                rowIndex++
            } else if (child.matches('.alignment-word')) {
                child.classList.toggle('is-collapsed-row', rowIndex < collapsedCount)
            }
        }
    }

    // Groups the Latin panel's own elements into rows the same way
    // #laTextPerRow does, but keeps element references (not just text) so
    // they can be individually marked collapsed -- including the gap that
    // *closes* each row (the heading separator, or an active la-gap),
    // since that's what visually separates it from the next row.
    #laRowGroups() {
        const rows = []
        let current = { elements: [] }

        const heading = this.laPanelTarget.querySelector('.alignment-heading-la')
        const separator = this.laPanelTarget.querySelector('.alignment-heading-separator')
        if (heading) {
            current.elements.push(heading)
            if (separator) current.elements.push(separator)
            rows.push(current)
            current = { elements: [] }
        }

        for (const child of this.laPanelTarget.children) {
            if (child === heading || child === separator) continue
            if (child.matches('.alignment-la-gap.is-boundary')) {
                current.elements.push(child)
                rows.push(current)
                current = { elements: [] }
            } else {
                // .alignment-la-sentence, or a splittable (non-boundary) gap
                // -- both belong to the row currently being accumulated.
                current.elements.push(child)
            }
        }
        rows.push(current)
        return rows
    }

    #collapseLaPanel(collapsedCount) {
        this.#laRowGroups().forEach((row, i) => {
            const collapsed = i < collapsedCount
            for (const el of row.elements) el.classList.toggle('is-collapsed-row', collapsed)
        })
    }

    #updateCollapseBanner() {
        if (!this.hasCollapseBannerTarget) return

        const count = this.#lastCollapsedCount
        if (count === 0) {
            this.collapseBannerTarget.hidden = true
            return
        }

        this.collapseBannerTarget.hidden = false
        const rowWoord = count === 1 ? 'rij' : 'rijen'
        this.collapseBannerTarget.textContent = this.columnsTarget.classList.contains('show-collapsed')
            ? `▾ ${count} voltooide ${rowWoord} verbergen`
            : `▸ ${count} voltooide ${rowWoord} ingeklapt (klik om te tonen)`
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    async save() {
        const layers = this.#currentLayers()

        this.#setStatus('Bezig met opslaan…')
        try {
            const resp = await fetch(this.saveUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({
                    layers: Object.fromEntries(
                        Object.entries(layers).map(([layer, rows]) => [
                            layer,
                            { rows: rows.map(({ la_start, words }) => ({ la_start, words })) },
                        ])
                    ),
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

    // Shared Latin row boundaries, read once: one la_start per row, in order.
    // A heading row (if present) always contributes -1 as row 0's la_start
    // -- a sentinel, never a real text_la offset (see HEADING_LA_START on
    // the PHP side).
    #laStarts() {
        const starts = []
        if (this.#headingOffset()) starts.push(-1)

        let sawFirstSentence = false
        for (const child of this.laPanelTarget.children) {
            if (child.matches('.alignment-la-sentence') && !sawFirstSentence) {
                starts.push(parseInt(child.dataset.offset, 10))
                sawFirstSentence = true
            } else if (child.matches('.alignment-la-gap.is-boundary')) {
                starts.push(parseInt(child.dataset.offset, 10))
            }
        }
        return starts
    }

    // One joined Latin text per row, in the same order as #laStarts(). Reads
    // each sentence's plain text from its `data-text` attribute rather than
    // `.textContent` -- a sentence now nests word-hover popup markup (lemma
    // + gloss), whose text `.textContent` would otherwise pull in too,
    // silently corrupting this and the live preview it feeds.
    #laTextPerRow() {
        const rows = []
        const heading = this.laPanelTarget.querySelector('.alignment-heading-la')
        if (heading) rows.push({ sentences: [heading.textContent] })
        rows.push({ sentences: [] })

        for (const child of this.laPanelTarget.children) {
            if (child.matches('.alignment-la-sentence')) {
                rows[rows.length - 1].sentences.push(child.dataset.text)
            } else if (child.matches('.alignment-la-gap.is-boundary')) {
                rows.push({ sentences: [] })
            }
        }
        return rows.map(r => r.sentences.join(' '))
    }

    // Reads one panel's current word groups -- one array of words per row.
    #wordsPerRow(panel) {
        const groups = [[]]
        for (const child of panel.children) {
            if (child.matches('.alignment-boundary')) {
                groups.push([])
            } else if (child.matches('.alignment-word')) {
                groups[groups.length - 1].push(child.textContent)
            }
        }
        return groups
    }

    // { layer: [{la_start, words}, ...] } for every translation panel present
    // -- used both to build the save payload and to render the live preview.
    #currentLayers() {
        const laStarts = this.#laStarts()
        const layers = {}
        for (const panel of this.translationPanelTargets) {
            const wordGroups = this.#wordsPerRow(panel)
            layers[panel.dataset.layer] = laStarts.map((la_start, i) => ({
                la_start, words: wordGroups[i] ?? [],
            }))
        }
        return layers
    }

    // ── Live preview (Latin + every translation, per row) ───────────────────────

    #renderPreview() {
        const laTexts = this.#laTextPerRow()
        const layers = this.#currentLayers()
        const layerNames = this.translationPanelTargets.map(p => p.dataset.layer)

        this.previewTarget.classList.toggle('has-weijenberg', layerNames.length > 1)
        this.previewTarget.replaceChildren(
            ...laTexts.map((laText, i) => {
                const rowEl = document.createElement('div')
                rowEl.className = 'alignment-preview-row'

                const laEl = document.createElement('p')
                laEl.className = 'institutio-sentence-la'
                laEl.textContent = laText
                rowEl.append(laEl)

                for (const layer of layerNames) {
                    const nlEl = document.createElement('p')
                    nlEl.className = 'institutio-sentence-nl'
                    nlEl.textContent = layers[layer][i].words.join(' ') || '(leeg)'
                    rowEl.append(nlEl)
                }

                return rowEl
            })
        )
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) this.statusTarget.textContent = msg
    }
}
