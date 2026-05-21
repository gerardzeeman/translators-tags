// assets/controllers/verse_compare_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    // Tracks whether a click-lock is active so hover doesn't override a click selection
    #clickLocked = false

    connect() {
        this.element.addEventListener('source-word:activate', this.#onSourceActivate.bind(this))
        this.element.addEventListener('source-word:hover',    this.#onSourceHover.bind(this))
        this.element.addEventListener('source-word:unhover',  this.#onSourceUnhover.bind(this))
        this.element.addEventListener('dutch-word:activate',  this.#onDutchActivate.bind(this))
        this.element.addEventListener('dutch-word:hover',     this.#onDutchHover.bind(this))
        this.element.addEventListener('dutch-word:unhover',   this.#onDutchUnhover.bind(this))
    }

    disconnect() {
        this.element.removeEventListener('source-word:activate', this.#onSourceActivate.bind(this))
        this.element.removeEventListener('source-word:hover',    this.#onSourceHover.bind(this))
        this.element.removeEventListener('source-word:unhover',  this.#onSourceUnhover.bind(this))
        this.element.removeEventListener('dutch-word:activate',  this.#onDutchActivate.bind(this))
        this.element.removeEventListener('dutch-word:hover',     this.#onDutchHover.bind(this))
        this.element.removeEventListener('dutch-word:unhover',   this.#onDutchUnhover.bind(this))
    }

    // ── Click handlers (set a persistent lock) ───────────────────────────────

    #onSourceActivate(event) {
        this.#clickLocked = true
        this.#clearAll()
        this.#highlightSource(event.detail)
    }

    #onDutchActivate(event) {
        this.#clickLocked = true
        this.#clearAll()
        this.#highlightDutch(event.detail)
    }

    // ── Hover handlers (only apply when no click lock is active) ─────────────

    #onSourceHover(event) {
        if (this.#clickLocked) return
        this.#clearHover()
        this.#highlightSource(event.detail, true)
    }

    #onSourceUnhover() {
        if (this.#clickLocked) return
        this.#clearHover()
    }

    #onDutchHover(event) {
        if (this.#clickLocked) return
        this.#clearHover()
        this.#highlightDutch(event.detail, true)
    }

    #onDutchUnhover() {
        if (this.#clickLocked) return
        this.#clearHover()
    }

    // ── Highlight logic ───────────────────────────────────────────────────────

    #highlightSource({ sourceId, linkedTwIds }, isHover = false) {
        const activeClass     = isHover ? 'hover-active' : 'active'
        const highlightClass  = isHover ? 'hover-highlighted' : 'highlighted'

        const srcEl = this.element.querySelector(`[data-source-id="${sourceId}"]`)
        if (srcEl) srcEl.classList.add(activeClass)

        const ids = (linkedTwIds || '')
            .split(',')
            .map(id => id.trim())
            .filter(id => id.length > 0)

        ids.forEach(id => {
            const el = this.element.querySelector(`[data-tw-id="${id}"]`)
            if (el) el.classList.add(highlightClass)
        })
    }

    #highlightDutch({ twId }, isHover = false) {
        const activeClass     = isHover ? 'hover-active' : 'active'
        const highlightClass  = isHover ? 'hover-highlighted' : 'highlighted'

        const dutchEl = this.element.querySelector(`[data-tw-id="${twId}"]`)
        if (dutchEl) dutchEl.classList.add(highlightClass)

        this.element
            .querySelectorAll('[data-linked-tw-ids]')
            .forEach(el => {
                const ids = (el.dataset.linkedTwIds || '')
                    .split(',')
                    .map(id => id.trim())
                    .filter(id => id.length > 0)

                if (ids.includes(String(twId))) {
                    el.classList.add(activeClass)
                }
            })
    }

    // ── Clear helpers ─────────────────────────────────────────────────────────

    #clearAll() {
        this.#clickLocked = false
        this.element.querySelectorAll('.active, .highlighted, .hover-active, .hover-highlighted')
            .forEach(el => el.classList.remove('active', 'highlighted', 'hover-active', 'hover-highlighted'))
    }

    #clearHover() {
        this.element.querySelectorAll('.hover-active, .hover-highlighted')
            .forEach(el => el.classList.remove('hover-active', 'hover-highlighted'))
    }
}
