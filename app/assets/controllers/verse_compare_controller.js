// assets/controllers/verse_compare_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    #clickLocked = false
    #activeTrans = 'SV'

    connect() {
        this.#syncStateFromDom()

        this.element.addEventListener('source-word:activate', this.#onSourceActivate)
        this.element.addEventListener('source-word:hover',    this.#onSourceHover)
        this.element.addEventListener('source-word:unhover',  this.#onSourceUnhover)
        this.element.addEventListener('dutch-word:activate',  this.#onDutchActivate)
        this.element.addEventListener('dutch-word:hover',     this.#onDutchHover)
        this.element.addEventListener('dutch-word:unhover',   this.#onDutchUnhover)

        // Re-apply JS state (active translation + button highlights) after Turbo
        // navigates the inner verse-frame (controller stays alive, frame swaps content)
        document.addEventListener('turbo:frame-load', this.#onFrameLoad)
    }

    disconnect() {
        this.element.removeEventListener('source-word:activate', this.#onSourceActivate)
        this.element.removeEventListener('source-word:hover',    this.#onSourceHover)
        this.element.removeEventListener('source-word:unhover',  this.#onSourceUnhover)
        this.element.removeEventListener('dutch-word:activate',  this.#onDutchActivate)
        this.element.removeEventListener('dutch-word:hover',     this.#onDutchHover)
        this.element.removeEventListener('dutch-word:unhover',   this.#onDutchUnhover)

        document.removeEventListener('turbo:frame-load', this.#onFrameLoad)
    }

    // ── Public Stimulus action ────────────────────────────────────────────────

    switchTranslation(event) {
        const code = event.currentTarget.dataset.translation
        if (!code || code === this.#activeTrans) return
        this.#activeTrans = code
        localStorage.setItem('ao:active-translation', code)
        this.#applyTranslationState(code)
        this.#clearAll()   // clear highlights — indicator set has changed
    }

    // ── Turbo frame reload handler ────────────────────────────────────────────

    #onFrameLoad = (event) => {
        // Alleen reageren op de verse-navigatieframe, niet op strongs-panel of andere frames.
        if (event.target.id !== 'verse-frame') return
        this.#applyTranslationState(this.#activeTrans)
        this.#clickLocked = false
    }

    // ── Initial DOM sync ──────────────────────────────────────────────────────

    #syncStateFromDom() {
        const saved = localStorage.getItem('ao:active-translation')
        const grid  = this.#grid()
        this.#activeTrans = saved ?? grid?.dataset.activeTranslation ?? 'SV'
        this.#applyTranslationState(this.#activeTrans)
    }

    // ── Apply active-translation state to DOM ─────────────────────────────────

    #applyTranslationState(code) {
        // Set CSS attribute used to toggle sv-indicators / hsv-indicators visibility
        const grid = this.#grid()
        if (grid) grid.dataset.activeTranslation = code

        // Sync switcher button active state
        this.element.querySelectorAll('[data-translation]').forEach(btn => {
            btn.classList.toggle('trans-btn-active', btn.dataset.translation === code)
        })
    }

    // ── Click handlers ────────────────────────────────────────────────────────

    #onSourceActivate = (event) => {
        this.#clearAll()         // clears highlights and resets lock
        this.#clickLocked = true // then lock so hover events are suppressed
        this.#highlightSource(event.detail)
    }

    #onDutchActivate = (event) => {
        this.#clearAll()
        this.#clickLocked = true
        this.#highlightDutch(event.detail)
    }

    // ── Hover handlers (only when no click-lock) ──────────────────────────────

    #onSourceHover = (event) => {
        if (this.#clickLocked) return
        this.#clearHover()
        this.#highlightSource(event.detail, true)
    }

    #onSourceUnhover = () => {
        if (this.#clickLocked) return
        this.#clearHover()
    }

    #onDutchHover = (event) => {
        if (this.#clickLocked) return
        this.#clearHover()
        this.#highlightDutch(event.detail, true)
    }

    #onDutchUnhover = () => {
        if (this.#clickLocked) return
        this.#clearHover()
    }

    // ── Highlight: source word → both Dutch panels ────────────────────────────

    #highlightSource({ sourceId, linkedSvIds, linkedHsvIds }, isHover = false) {
        const srcCls = isHover ? 'hover-active'       : 'active'
        const hlCls  = isHover ? 'hover-highlighted'  : 'highlighted'

        // Highlight the source word itself
        const srcEl = this.element.querySelector(`[data-source-id="${sourceId}"]`)
        if (srcEl) srcEl.classList.add(srcCls)

        // Highlight corresponding words in the SV panel
        this.#highlightByTwIds(linkedSvIds, hlCls)
        // Highlight corresponding words in the HSV panel
        this.#highlightByTwIds(linkedHsvIds, hlCls)
    }

    // ── Highlight: Dutch word → source word + both Dutch panels ──────────────

    #highlightDutch({ twId }, isHover = false) {
        const srcCls = isHover ? 'hover-active'       : 'active'
        const hlCls  = isHover ? 'hover-highlighted'  : 'highlighted'
        const twStr  = String(twId)

        // Highlight the clicked Dutch word
        const dutchEl = this.element.querySelector(`[data-tw-id="${twStr}"]`)
        if (dutchEl) dutchEl.classList.add(hlCls)

        // Find all source words linked to this tw_id (via either translation)
        // and cross-highlight in both Dutch panels via their link sets.
        this.element.querySelectorAll('[data-source-id]').forEach(srcEl => {
            const svIds  = this.#splitIds(srcEl.dataset.linkedSvIds)
            const hsvIds = this.#splitIds(srcEl.dataset.linkedHsvIds)

            if (svIds.includes(twStr) || hsvIds.includes(twStr)) {
                srcEl.classList.add(srcCls)
                this.#highlightByTwIds(srcEl.dataset.linkedSvIds,  hlCls)
                this.#highlightByTwIds(srcEl.dataset.linkedHsvIds, hlCls)
            }
        })
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    #highlightByTwIds(idsStr, cssClass) {
        this.#splitIds(idsStr).forEach(id => {
            const el = this.element.querySelector(`[data-tw-id="${id}"]`)
            if (el) el.classList.add(cssClass)
        })
    }

    #grid() {
        return this.element.querySelector('.verse-compare-grid, .chapter-verse-grid')
    }

    #splitIds(idsStr) {
        return (idsStr || '').split(',').map(s => s.trim()).filter(Boolean)
    }

    #clearAll() {
        this.#clickLocked = false
        this.element
            .querySelectorAll('.active, .highlighted, .hover-active, .hover-highlighted')
            .forEach(el => el.classList.remove('active', 'highlighted', 'hover-active', 'hover-highlighted'))
    }

    #clearHover() {
        this.element
            .querySelectorAll('.hover-active, .hover-highlighted')
            .forEach(el => el.classList.remove('hover-active', 'hover-highlighted'))
    }
}
