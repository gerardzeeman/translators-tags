// assets/controllers/source_word_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    click() {
        this.element.dispatchEvent(new CustomEvent('source-word:activate', {
            bubbles: true,
            detail: {
                sourceId:    this.element.dataset.sourceId,
                linkedTwIds: this.element.dataset.linkedTwIds,
                lang:        this.element.dataset.lang,
                strongs:     this.element.dataset.strongs,
            }
        }))
    }
}
