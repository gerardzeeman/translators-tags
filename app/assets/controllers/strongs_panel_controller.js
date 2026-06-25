import { Controller } from '@hotwired/stimulus'

/**
 * Beheert het Strong's zijpaneel (desktop) en bottom sheet (mobiel).
 *
 * Geplaatst op .page-chapter-view. Luistert via event-delegatie naar klikken
 * op alle elementen met data-turbo-frame="strongs-panel". Turbo zorgt voor het
 * laden van de frame-inhoud; deze controller toont/verbergt het paneel.
 */
export default class extends Controller {
    static targets = ['sidebar']

    connect() {
        this.element.addEventListener('click', this.#onBodyClick)
    }

    disconnect() {
        this.element.removeEventListener('click', this.#onBodyClick)
    }

    close() {
        this.sidebarTarget.classList.remove('is-open')
        this.element.classList.remove('strongs-open')
    }

    // ── Private ───────────────────────────────────────────────────────────────

    #onBodyClick = (event) => {
        // Alleen reageren op klikken die gericht zijn op het strongs-panel frame
        const link = event.target.closest('[data-turbo-frame="strongs-panel"]')
        if (!link) return

        this.sidebarTarget.classList.add('is-open')
        this.element.classList.add('strongs-open')
    }
}
