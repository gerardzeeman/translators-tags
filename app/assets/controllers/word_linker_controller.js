// assets/controllers/word_linker_controller.js
import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export default class extends Controller {
    static targets = ['sourceWord', 'dutchWord', 'actionBar', 'selectedLabel', 'status']
    static values  = { saveUrl: String, deleteUrl: String, refreshUrl: String, progressUrl: String, translationId: Number }

    #selectedSourceId   = null
    #selectedSourceLang = null
    #selectedTwIds      = new Set()
    #existingLinkIds    = new Map()

    // ── Source word selected ──────────────────────────────────────────────────

    selectSource(event) {
        event.stopPropagation()
        const el       = event.currentTarget
        const sourceId = el.dataset.sourceId
        const lang     = el.dataset.lang

        // Clicking the same word again deselects
        if (this.#selectedSourceId === sourceId) {
            this.#reset()
            return
        }

        this.#reset(false, false)

        this.#selectedSourceId   = sourceId
        this.#selectedSourceLang = lang

        el.classList.add('src-word-active')

        // Pre-select already-linked Dutch words
        const linkedIds = (el.dataset.linkedTwIds || '')
            .split(',').map(s => s.trim()).filter(Boolean)

        this.#existingLinkIds.clear()
        el.querySelectorAll('.link-chip[data-link-id]').forEach(chip => {
            this.#existingLinkIds.set(chip.dataset.twId, chip.dataset.linkId)
        })

        linkedIds.forEach(id => {
            this.#selectedTwIds.add(id)
            const dw = this.#findDutchWord(id)
            if (dw) dw.classList.add('nl-word-selected')
        })

        this.#showActionBar(el)
        this.#setStatus('Geselecteerd: klik Nederlandse woorden om te koppelen.')
        this.#loadDetailPanels(el)
    }

    // ── Dutch word toggled ────────────────────────────────────────────────────

    selectDutch(event) {
        event.stopPropagation()

        // Silently ignore if no source word is active
        if (!this.#selectedSourceId) return

        const el   = event.currentTarget
        const twId = el.dataset.twId

        if (this.#selectedTwIds.has(twId)) {
            this.#selectedTwIds.delete(twId)
            el.classList.remove('nl-word-selected')
        } else {
            this.#selectedTwIds.add(twId)
            el.classList.add('nl-word-selected')
        }
    }

    // ── Click on controller element but not on a word = cancel ───────────────

    backgroundClick(event) {
        // Only reset if the click landed directly on the controller root or
        // a non-interactive container — not on a word or button
        if (this.#selectedSourceId) {
            this.#reset()
        }
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    async saveLinks(event) {
        event.stopPropagation()
        if (!this.#selectedSourceId) return

        const twIds = [...this.#selectedTwIds]

        try {
            const resp = await fetch(this.saveUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({
                    lang:           this.#selectedSourceLang,
                    source_word_id: parseInt(this.#selectedSourceId),
                    tw_ids:         twIds.map(Number),
                    translation_id: this.translationIdValue,
                }),
            })

            const data = await resp.json()
            if (!data.success) throw new Error(data.error || 'Save failed')

            if (this.hasRefreshUrlValue) {
                // ── Strongs view: replace only this verse block + update progress bar ──
                this.#reset()
                await this.#refreshVerseBlock()        // replaces this.element in DOM
                await this.#refreshProgressBar()       // updates counters & bar widths
            } else {
                // ── Passage view: full reload (single verse, fast enough) ──
                this.#updateSourceWordDOM(twIds, data.empty)
                this.#setStatus(data.empty
                    ? '✓ Opgeslagen: geen koppeling (handmatig leeg).'
                    : `✓ ${data.linked} koppeling(en) opgeslagen.`)
                this.#reset()
                setTimeout(() => window.location.reload(), 600)
            }

        } catch (err) {
            this.#setStatus(`Fout bij opslaan: ${err.message}`)
        }
    }

    // ── Confirm all proposals ────────────────────────────────────────────────

    #confirmPending = false

    async confirmAllProposals(event) {
        event?.stopPropagation()
        if (this.#confirmPending) return
        this.#confirmPending = true

        const proposals = this.sourceWordTargets.filter(el =>
            el.classList.contains('src-word-propagated') &&
            el.dataset.linkedTwIds
        )

        if (proposals.length === 0) {
            this.#setStatus('Geen voorstellen gevonden om te bevestigen.')
            this.#confirmPending = false
            return
        }

        if (!confirm(`${proposals.length} voorstellen bevestigen als handmatige koppeling?`)) {
            this.#confirmPending = false
            return
        }

        this.#setStatus(`Bezig met opslaan (0 / ${proposals.length})…`)

        let saved = 0
        const errors = []

        for (const el of proposals) {
            const twIds = el.dataset.linkedTwIds
                .split(',').map(s => s.trim()).filter(Boolean).map(Number)
            if (!twIds.length) continue

            try {
                const resp = await fetch(this.saveUrlValue, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                    body: JSON.stringify({
                        lang:           el.dataset.lang,
                        source_word_id: parseInt(el.dataset.sourceId),
                        tw_ids:         twIds,
                        translation_id: this.translationIdValue,
                    }),
                })
                const data = await resp.json()
                if (!data.success) throw new Error(data.error || 'Save failed')
                saved++
            } catch (err) {
                errors.push(`woord ${el.dataset.sourceId}: ${err.message}`)
            }

            this.#setStatus(`Bezig met opslaan (${saved} / ${proposals.length})…`)
        }

        if (errors.length) {
            this.#setStatus(`${saved} opgeslagen, ${errors.length} mislukt. Pagina wordt herladen…`)
            console.warn('confirmAllProposals errors:', errors)
        } else {
            this.#setStatus(`✓ ${saved} koppeling(en) bevestigd. Pagina wordt herladen…`)
        }

        setTimeout(() => window.location.reload(), 800)
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    cancel(event) {
        event?.stopPropagation()
        this.#reset()
        this.#setStatus('Koppeling geannuleerd.')
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    // clearPanels is false when #reset() is called right before selecting a
    // *different* word (selectSource already resets state before applying the
    // new selection) — fresh panel content is about to load anyway, so
    // flashing the placeholder first would just be visual noise.
    #reset(clearStatus = true, clearPanels = true) {
        this.sourceWordTargets.forEach(el => el.classList.remove('src-word-active'))
        this.dutchWordTargets.forEach(el => el.classList.remove('nl-word-selected'))

        this.#selectedSourceId   = null
        this.#selectedSourceLang = null
        this.#selectedTwIds      = new Set()
        this.#existingLinkIds    = new Map()

        if (this.hasActionBarTarget) {
            this.actionBarTargets.forEach(el => el.style.display = 'none')
        }
        if (clearStatus) this.#setStatus('Klik een bronwoord om te beginnen.')
        if (clearPanels) this.#clearDetailPanels()
    }

    #findDutchWord(twId) {
        return this.dutchWordTargets.find(el => el.dataset.twId === twId) || null
    }

    #showActionBar(sourceEl) {
        const label = sourceEl.querySelector('.src-text')?.textContent?.trim()
                    || sourceEl.dataset.strongs || '–'

        if (this.hasActionBarTarget) {
            this.actionBarTargets.forEach(el => el.style.display = 'block')
        }
        if (this.hasSelectedLabelTarget) {
            this.selectedLabelTargets.forEach(el => el.textContent = label)
        }
    }

    #setStatus(msg) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = msg
        }
    }

    #updateSourceWordDOM(twIds, isEmpty = false) {
        const srcEl = this.sourceWordTargets.find(
            el => el.dataset.sourceId === this.#selectedSourceId
        )
        if (!srcEl) return
        srcEl.dataset.linkedTwIds = twIds.join(',')
        srcEl.dataset.manuallyEmpty = isEmpty ? '1' : '0'

        // Update border style to reflect new state immediately
        srcEl.classList.remove(
            'method-border-manual', 'method-border-manual-empty',
            'method-border-pivot',  'method-border-heuristic',
            'method-border-none'
        )
        srcEl.classList.add(isEmpty ? 'method-border-manual-empty' : 'method-border-manual')
    }

    #deleteUrl(linkId) {
        return this.deleteUrlValue.replace(/\/0$/, '/' + linkId)
    }

    // ── Detail panels (Strong's + all-links) ──────────────────────────────────
    // Loaded via plain fetch rather than Turbo Frame anchor navigation: both
    // panels update from the same word click, and a single click can't target
    // two Turbo Frames at once.

    #loadDetailPanels(sourceEl) {
        this.#loadFrame('strongs-panel', sourceEl.dataset.strongsUrl,
            'Geen Strong\'s-nummer voor dit woord.')
        this.#loadFrame('word-links-panel', sourceEl.dataset.wordLinksUrl, null)
    }

    #clearDetailPanels() {
        this.#placeholder('strongs-panel', 'Selecteer een bronwoord om details te zien.')
        this.#placeholder('word-links-panel', 'Selecteer een bronwoord om alle koppelingen te zien.')
    }

    async #loadFrame(frameId, url, emptyMessage) {
        const frame = document.getElementById(frameId)
        // Frame may not be in the DOM at all (e.g. word-links-panel is only
        // rendered for ROLE_LINKER) — nothing to do.
        if (!frame) return

        if (!url) {
            this.#placeholder(frameId, emptyMessage)
            return
        }

        try {
            const resp = await fetch(url, { headers: { 'Accept': 'text/html' } })
            if (!resp.ok) return

            const html = await resp.text()
            // Parse via DOMParser (safer than innerHTML, avoids script execution)
            const doc = new DOMParser().parseFromString(html, 'text/html')
            const newFrame = doc.getElementById(frameId)
            if (newFrame) frame.innerHTML = newFrame.innerHTML
        } catch {
            // Detail panels are supplementary — a failed fetch shouldn't
            // interrupt the linking workflow, just leave the previous content.
        }
    }

    #placeholder(frameId, message) {
        const frame = document.getElementById(frameId)
        if (!frame || !message) return
        frame.replaceChildren()
        const p = document.createElement('p')
        p.className = 'strongs-placeholder'
        p.textContent = message
        frame.appendChild(p)
    }

    // ── Partial refresh helpers (Strongs view only) ───────────────────────────

    async #refreshVerseBlock() {
        const resp = await fetch(this.refreshUrlValue)
        if (!resp.ok) return

        const html = await resp.text()

        // Parse via DOMParser (safer than innerHTML, avoids script execution)
        const doc = new DOMParser().parseFromString(html.trim(), 'text/html')
        const newBlock = doc.body.firstElementChild

        // Swap: Stimulus will auto-disconnect the old controller and
        // auto-connect the new one via its MutationObserver.
        this.element.replaceWith(newBlock)
    }

    async #refreshProgressBar() {
        if (!this.hasProgressUrlValue) return

        const resp = await fetch(this.progressUrlValue)
        if (!resp.ok) return

        const d = await resp.json()

        const total  = parseInt(d.total  ?? 0)
        const manual = parseInt(d.manual ?? 0)
        const linked = parseInt(d.linked ?? 0)
        const pctManual = total > 0 ? Math.round(manual / total * 100) : 0
        const pctLinked = total > 0 ? Math.round(linked / total * 100) : 0

        const get = id => document.getElementById(id)

        const barLinked = get('progress-bar-linked')
        const barManual = get('progress-bar-manual')
        if (barLinked) barLinked.style.width = pctLinked + '%'
        if (barManual) barManual.style.width = pctManual + '%'

        const cManual = get('progress-count-manual')
        const cLinked = get('progress-count-linked')
        const cTotal  = get('progress-count-total')
        const pct     = get('progress-pct')
        if (cManual) cManual.textContent = manual
        if (cLinked) cLinked.textContent = linked
        if (cTotal)  cTotal.textContent  = total
        if (pct)     pct.textContent     = pctManual + '% handmatig bevestigd'
    }
}