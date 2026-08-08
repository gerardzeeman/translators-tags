// assets/controllers/source_word_controller.js
import { Controller } from '@hotwired/stimulus'
import { linkedIdsFromDataset } from '../translation_links.js'

export default class extends Controller {
    connect() {
        this.element.addEventListener('click',      this.#onClick)
        this.element.addEventListener('mouseenter', this.#onMouseEnter)
        this.element.addEventListener('mouseleave', this.#onMouseLeave)
    }

    disconnect() {
        this.element.removeEventListener('click',      this.#onClick)
        this.element.removeEventListener('mouseenter', this.#onMouseEnter)
        this.element.removeEventListener('mouseleave', this.#onMouseLeave)
    }

    #dispatch = (type) => {
        this.element.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: {
                sourceId:  this.element.dataset.sourceId,
                linkedIds: linkedIdsFromDataset(this.element.dataset),
                lang:      this.element.dataset.lang,
                strongs:   this.element.dataset.strongs,
            }
        }))
    }

    #onClick      = () => this.#dispatch('source-word:activate')
    #onMouseEnter = () => this.#dispatch('source-word:hover')
    #onMouseLeave = () => this.#dispatch('source-word:unhover')
}
