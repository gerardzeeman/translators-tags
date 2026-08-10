import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['list']

    toggle(event) {
        event.stopPropagation()
        const isOpen = !this.listTarget.hasAttribute('hidden')
        this.#closeAllOpen()
        if (!isOpen) {
            this.listTarget.removeAttribute('hidden')
            document.addEventListener('click', this.#onOutsideClick, { once: true })
        }
    }

    // Queries by the Stimulus target attribute (always present) rather than the
    // .cross-ref-list styling class, so this doesn't silently break if the
    // template's markup/classes change independently of this controller.
    #closeAllOpen() {
        document.querySelectorAll('[data-cross-ref-toggle-target="list"]:not([hidden])')
            .forEach(el => el.setAttribute('hidden', ''))
    }

    #onOutsideClick = () => this.#closeAllOpen()
}
