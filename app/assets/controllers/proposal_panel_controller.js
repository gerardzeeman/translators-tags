import { Controller } from '@hotwired/stimulus'

/**
 * Beheert het vertaalvoorstel-zijpaneel op /institutie/bewerk/{id} --
 * exact hetzelfde patroon als verse_panel_controller.js (open/sluiten via
 * CSS-klassen, event-delegatie op data-turbo-frame-klikken, Turbo laadt de
 * frame-inhoud zelf). Los van verse-panel omdat het op een andere pagina
 * zit met een eigen frame-id ("institutio-proposal-panel").
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
        this.element.classList.remove('proposal-panel-open')
    }

    // ── Private ───────────────────────────────────────────────────────────────

    #onBodyClick = (event) => {
        const link = event.target.closest('[data-turbo-frame="institutio-proposal-panel"]')
        if (!link) return

        this.sidebarTarget.classList.add('is-open')
        this.element.classList.add('proposal-panel-open')
    }
}
