// assets/controllers/source_word_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    connect() {
        this.element.addEventListener('click',      this.#onClick.bind(this))
        this.element.addEventListener('mouseenter', this.#onMouseEnter.bind(this))
        this.element.addEventListener('mouseleave', this.#onMouseLeave.bind(this))
    }

    disconnect() {
        this.element.removeEventListener('click',      this.#onClick.bind(this))
        this.element.removeEventListener('mouseenter', this.#onMouseEnter.bind(this))
        this.element.removeEventListener('mouseleave', this.#onMouseLeave.bind(this))
    }

    #dispatch(type) {
        this.element.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: {
                sourceId:    this.element.dataset.sourceId,
                linkedTwIds: this.element.dataset.linkedTwIds,
                lang:        this.element.dataset.lang,
                strongs:     this.element.dataset.strongs,
            }
        }))
    }

    #onClick()      { this.#dispatch('source-word:activate') }
    #onMouseEnter() { this.#dispatch('source-word:hover') }
    #onMouseLeave() { this.#dispatch('source-word:unhover') }
}
