// assets/controllers/word_linker_controller.js
import { Controller } from '@hotwired/stimulus'
import { confirmDialog } from '../confirm_dialog.js'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export default class extends Controller {
    static targets = ['sourceWord', 'dutchWord', 'actionBar', 'selectedLabel', 'status', 'confirmProposalsButton', 'cancelProposalsButton']
    static values  = { saveUrl: String, deleteUrl: String, refreshUrl: String, progressUrl: String, translationId: Number }

    #selectedSourceId   = null
    #selectedSourceLang = null
    #selectedTwIds      = new Set()
    #existingLinkIds    = new Map()
    // Scrollpositie van vóór de selectie — vastgelegd in selectSource(), niet
    // pas bij opslaan: zodra de Strong's/Alle-links-panelen en de actiebalk
    // verschijnen/verdwijnen kan de pagina-inhoud nog verschuiven (en dus
    // window.scrollY veranderen) vóórdat er daadwerkelijk wordt opgeslagen.
    #preSelectionScrollY = 0

    // ── Source word selected ──────────────────────────────────────────────────

    selectSource(event) {
        event.preventDefault() // ook aangeroepen via keydown.space — voorkomt scrollen
        event.stopPropagation()
        const el       = event.currentTarget
        const sourceId = el.dataset.sourceId
        const lang     = el.dataset.lang

        // Clicking the same word again deselects
        if (this.#selectedSourceId === sourceId) {
            this.#reset()
            this.#restoreSmoothScroll()
            return
        }

        this.#reset(false, false)
        this.#preSelectionScrollY = window.scrollY
        // De site-brede scroll-behavior:smooth (html-element) maakt van elke
        // scrollpositie-aanpassing tijdens deze koppel-interactie — het
        // verschijnen van de actiebalk, het laden van de Strong's/Alle-links-
        // panelen — een trage, zichtbare animatie i.p.v. een onmerkbare
        // sprong, tot een paar seconden merkbare "hobbel" toe. Tijdelijk uit,
        // weer aan zodra deze interactie eindigt (annuleren, of ná de reload
        // die op opslaan/verwijderen/bulk-bevestigen volgt).
        this.#disableSmoothScroll()

        this.#selectedSourceId   = sourceId
        this.#selectedSourceLang = lang

        el.classList.add('src-word-active')
        el.setAttribute('aria-pressed', 'true')

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
            if (dw) { dw.classList.add('nl-word-selected'); dw.setAttribute('aria-pressed', 'true') }
        })

        this.#showActionBar(el)
        this.#setStatus('Geselecteerd: klik Nederlandse woorden om te koppelen.')
        this.#loadDetailPanels(el)
    }

    // ── Dutch word toggled ────────────────────────────────────────────────────

    selectDutch(event) {
        event.preventDefault() // ook aangeroepen via keydown.space — voorkomt scrollen
        event.stopPropagation()

        // Silently ignore if no source word is active
        if (!this.#selectedSourceId) return

        const el   = event.currentTarget
        const twId = el.dataset.twId

        if (this.#selectedTwIds.has(twId)) {
            this.#selectedTwIds.delete(twId)
            el.classList.remove('nl-word-selected')
            el.setAttribute('aria-pressed', 'false')
        } else {
            this.#selectedTwIds.add(twId)
            el.classList.add('nl-word-selected')
            el.setAttribute('aria-pressed', 'true')
        }
    }

    // ── Eén koppeling verwijderen (klik op de "×"-chip) ─────────────────────────
    // De chip zit binnen .src-word, dus zonder stopPropagation zou een klik
    // hier doorbubbelen naar selectSource en het hele woord (de-)selecteren
    // in plaats van alleen deze ene koppeling te verwijderen.

    async deleteLink(event) {
        event.preventDefault()
        event.stopPropagation()

        const chip   = event.currentTarget
        const linkId = chip.dataset.linkId
        if (!linkId) return
        const scrollY = this.#preSelectionScrollY

        try {
            const resp = await fetch(this.#deleteUrl(linkId), {
                method:  'DELETE',
                headers: { 'X-CSRF-Token': csrfToken() },
            })
            if (!resp.ok) throw new Error('' + resp.status)

            chip.remove()
            this.#setStatus('✓ Koppeling verwijderd.')

            if (this.hasRefreshUrlValue) {
                await this.#refreshVerseBlock()
                await this.#refreshProgressBar()
            } else {
                setTimeout(() => this.#reloadCurrentView(scrollY), 400)
            }
        } catch {
            this.#setStatus('Verwijderen van de koppeling is mislukt. Controleer je verbinding en probeer opnieuw.')
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

        const button = event.currentTarget
        button.disabled = true // voorkomt dubbele indiening bij dubbelklik / trage verbinding

        const scrollY = this.#preSelectionScrollY
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
                // ── Passage view ──
                this.#updateSourceWordDOM(twIds, data.empty)
                this.#setStatus(data.empty
                    ? '✓ Opgeslagen: geen koppeling (handmatig leeg).'
                    : `✓ ${data.linked} koppeling(en) opgeslagen.`)
                this.#reset()
                setTimeout(() => this.#reloadCurrentView(scrollY), 600)
            }

        } catch {
            this.#setStatus('Opslaan is mislukt. Controleer je internetverbinding en probeer opnieuw.')
            button.disabled = false
        }
    }

    // ── Confirm all proposals ────────────────────────────────────────────────

    #confirmPending  = false
    #confirmAbort    = null

    async confirmAllProposals(event) {
        event?.stopPropagation()
        if (this.#confirmPending) return
        const scrollY = window.scrollY

        const proposals = this.sourceWordTargets.filter(el =>
            el.classList.contains('src-word-propagated') &&
            el.dataset.linkedTwIds
        )

        if (proposals.length === 0) {
            this.#setStatus('Geen voorstellen gevonden om te bevestigen.')
            return
        }

        const confirmed = await confirmDialog(
            `${proposals.length} voorstellen bevestigen als handmatige koppeling?`,
            { confirmLabel: 'Bevestig alle', danger: false }
        )
        if (!confirmed) return

        this.#confirmPending = true
        this.#confirmAbort   = new AbortController()
        this.#toggleConfirmButtons(true)
        this.#setStatus(`Bezig met opslaan (0 / ${proposals.length})…`)

        let saved = 0
        let cancelled = false
        const errors = []

        for (const el of proposals) {
            if (this.#confirmAbort.signal.aborted) { cancelled = true; break }

            const twIds = el.dataset.linkedTwIds
                .split(',').map(s => s.trim()).filter(Boolean).map(Number)
            if (!twIds.length) continue

            try {
                const resp = await fetch(this.saveUrlValue, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                    signal:  this.#confirmAbort.signal,
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
                if (err.name === 'AbortError') { cancelled = true; break }
                errors.push(el.dataset.sourceId)
            }

            this.#setStatus(`Bezig met opslaan (${saved} / ${proposals.length})…`)
        }

        this.#confirmPending = false
        this.#confirmAbort   = null
        this.#toggleConfirmButtons(false)

        if (cancelled) {
            this.#setStatus(`Geannuleerd na ${saved} van ${proposals.length} koppeling(en). Pagina wordt ververst…`)
        } else if (errors.length) {
            this.#setStatus(`${saved} opgeslagen, ${errors.length} mislukt. Pagina wordt ververst…`)
            console.warn('confirmAllProposals mislukt voor woord-id\'s:', errors)
        } else {
            this.#setStatus(`✓ ${saved} koppeling(en) bevestigd. Pagina wordt ververst…`)
        }

        setTimeout(() => this.#reloadCurrentView(scrollY), 800)
    }

    cancelConfirmAllProposals(event) {
        event?.stopPropagation()
        this.#confirmAbort?.abort()
    }

    #toggleConfirmButtons(running) {
        if (this.hasConfirmProposalsButtonTarget) {
            this.confirmProposalsButtonTargets.forEach(el => { el.hidden = running })
        }
        if (this.hasCancelProposalsButtonTarget) {
            this.cancelProposalsButtonTargets.forEach(el => { el.hidden = !running })
        }
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    cancel(event) {
        event?.stopPropagation()
        this.#reset()
        this.#restoreSmoothScroll()
        this.#setStatus('Koppeling geannuleerd.')
    }

    // Turbo-visit i.p.v. een harde reload — zelfde patroon als elders in de
    // app (zie historical_alignment_controller.js): geen volledige page-flash,
    // geen opnieuw laden van fonts/CSS, behoudt de Turbo-navigatiehistorie.
    // Een Turbo-visit scrollt net als een gewone navigatie standaard terug
    // naar boven — daarom krijgt deze methode de scrollpositie van vóór de
    // actie aangereikt (op het moment van selecteren vastgelegd door de
    // aanroeper, niet hier: de pagina kan intussen al verschoven zijn) en
    // herstelt die na afloop (turbo:load). behavior:'instant' + het kort erna
    // nogmaals toepassen zijn een extra vangnet; de eigenlijke fix tegen de
    // zichtbare "hobbel" is dat scroll-behavior:smooth voor deze hele
    // interactie al bij het selecteren is uitgezet (zie selectSource) en hier,
    // aan het einde, weer wordt aangezet.
    #reloadCurrentView(scrollY = window.scrollY) {
        if (window.Turbo) {
            const applyScroll = () => window.scrollTo({ top: scrollY, behavior: 'instant' })
            const restoreScroll = () => {
                document.removeEventListener('turbo:load', restoreScroll)
                applyScroll()
                setTimeout(() => { applyScroll(); this.#restoreSmoothScroll() }, 800)
            }
            document.addEventListener('turbo:load', restoreScroll)
            window.Turbo.visit(window.location.href, { action: 'replace' })
        } else {
            window.location.reload()
        }
    }

    // ── Scroll-behavior:smooth tijdelijk uit tijdens een koppel-interactie ────
    // (zie selectSource): voorkomt dat elke scrollpositie-aanpassing die het
    // verschijnen/verdwijnen van panelen en de actiebalk veroorzaakt, wordt
    // uitvergroot tot een trage, seconden durende scrollanimatie.

    #disableSmoothScroll() {
        document.documentElement.style.scrollBehavior = 'auto'
    }

    #restoreSmoothScroll() {
        document.documentElement.style.removeProperty('scroll-behavior')
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    // clearPanels is false when #reset() is called right before selecting a
    // *different* word (selectSource already resets state before applying the
    // new selection) — fresh panel content is about to load anyway, so
    // flashing the placeholder first would just be visual noise.
    #reset(clearStatus = true, clearPanels = true) {
        this.sourceWordTargets.forEach(el => { el.classList.remove('src-word-active'); el.setAttribute('aria-pressed', 'false') })
        this.dutchWordTargets.forEach(el => { el.classList.remove('nl-word-selected'); el.setAttribute('aria-pressed', 'false') })

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
            // Turbo-Frame-header meesturen zodat de server dit als een frame-verzoek
            // herkent en het lichte fragment teruggeeft i.p.v. de volledige pagina
            // (die de backend nu rendert voor top-level navigaties zonder deze header).
            const resp = await fetch(url, { headers: { 'Accept': 'text/html', 'Turbo-Frame': frameId } })
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