// assets/controllers/dutch_word_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    connect() {
        this.element.addEventListener('click',      this.#onClick)
        this.element.addEventListener('keydown',    this.#onKeyDown)
        this.element.addEventListener('mouseenter', this.#onMouseEnter)
        this.element.addEventListener('mouseleave', this.#onMouseLeave)
    }

    disconnect() {
        this.element.removeEventListener('click',      this.#onClick)
        this.element.removeEventListener('keydown',    this.#onKeyDown)
        this.element.removeEventListener('mouseenter', this.#onMouseEnter)
        this.element.removeEventListener('mouseleave', this.#onMouseLeave)
    }

    #dispatch = (type) => {
        this.element.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: {
                twId:   this.element.dataset.twId,
                method: this.element.dataset.method,
                score:  this.element.dataset.score,
            }
        }))
    }

    #onClick      = () => this.#dispatch('dutch-word:activate')
    #onMouseEnter = () => this.#dispatch('dutch-word:hover')
    #onMouseLeave = () => this.#dispatch('dutch-word:unhover')

    #onKeyDown = (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            this.#onClick()
        }
    }
}
