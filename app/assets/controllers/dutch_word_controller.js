// assets/controllers/dutch_word_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    click() {
        this.element.dispatchEvent(new CustomEvent('dutch-word:activate', {
            bubbles: true,
            detail: {
                twId:   this.element.dataset.twId,
                method: this.element.dataset.method,
                score:  this.element.dataset.score,
            }
        }))
    }
}
