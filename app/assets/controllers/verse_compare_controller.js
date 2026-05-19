// assets/controllers/verse_compare_controller.js
import { Controller } from '@hotwired/stimulus'

/**
 * verse-compare controller
 * Coordinates highlighting between source words and Dutch words.
 * Mounted on the .page-verse element.
 */
export default class extends Controller {
    connect() {
        // Listen for custom highlight events from child controllers
        this.element.addEventListener('source-word:activate', this.#onSourceActivate.bind(this))
        this.element.addEventListener('dutch-word:activate',  this.#onDutchActivate.bind(this))
    }

    disconnect() {
        this.element.removeEventListener('source-word:activate', this.#onSourceActivate.bind(this))
        this.element.removeEventListener('dutch-word:activate',  this.#onDutchActivate.bind(this))
    }

    #onSourceActivate(event) {
        const { sourceId, linkedTwIds } = event.detail
        this.#clearAll()

        // Highlight the clicked source word
        const srcEl = this.element.querySelector(`[data-source-id="${sourceId}"]`)
        srcEl?.classList.add('active')

        // Highlight all linked Dutch words
        const ids = (linkedTwIds || '').split(',').filter(Boolean)
        ids.forEach(id => {
            const el = this.element.querySelector(`[data-tw-id="${id}"]`)
            el?.classList.add('highlighted')
        })
    }

    #onDutchActivate(event) {
        const { twId } = event.detail
        this.#clearAll()

        // Highlight the clicked Dutch word
        const dutchEl = this.element.querySelector(`[data-tw-id="${twId}"]`)
        dutchEl?.classList.add('highlighted')

        // Highlight all source words that link to this Dutch word
        this.element.querySelectorAll('[data-linked-tw-ids]').forEach(el => {
            const ids = el.dataset.linkedTwIds.split(',')
            if (ids.includes(String(twId))) {
                el.classList.add('active')
            }
        })
    }

    #clearAll() {
        this.element.querySelectorAll('.active').forEach(el => el.classList.remove('active'))
        this.element.querySelectorAll('.highlighted').forEach(el => el.classList.remove('highlighted'))
    }
}
