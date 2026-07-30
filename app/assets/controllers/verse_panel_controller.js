import { Controller } from '@hotwired/stimulus'

/**
 * Beheert het Bijbeltekst-zijpaneel (desktop) en bottom sheet (mobiel) op
 * /institutie. Geplaatst op .page-institutio. Luistert via event-delegatie
 * naar klikken op alle elementen met data-turbo-frame="institutio-verse-panel".
 * Turbo zorgt voor het laden van de frame-inhoud; deze controller toont/
 * verbergt het paneel. Zelfde patroon als strongs_panel_controller.js.
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
        this.element.classList.remove('verse-panel-open')
    }

    // ── Private ───────────────────────────────────────────────────────────────

    #onBodyClick = (event) => {
        const link = event.target.closest('[data-turbo-frame="institutio-verse-panel"]')
        if (!link) return

        this.sidebarTarget.classList.add('is-open')
        this.element.classList.add('verse-panel-open')
    }
}
