import { Controller } from '@hotwired/stimulus'

/**
 * Scrollt het actieve hoofdstuk in de mobiele strip in beeld.
 * Werkt zowel bij initieel laden als bij Turbo-navigatie.
 */
export default class extends Controller {
    connect() {
        const active = this.element.querySelector('.mobile-chapter-btn.is-active')
        active?.scrollIntoView({ inline: 'center', block: 'nearest' })
    }
}
