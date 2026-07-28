// assets/controllers/embed_highlight_controller.js
import { Controller } from '@hotwired/stimulus'

// Self-contained hover-highlight for a single blog embed block: hovering a
// source word highlights its linked translation word(s) and vice versa.
// Unlike source_word/dutch_word_controller.js (which coordinate across a
// multi-translation page via bubbling events), an embed only ever shows one
// translation, so a plain target-based controller is simpler here.
export default class extends Controller {
    static targets = ['sourceWord', 'dutchWord']

    highlightSource(event) {
        const ids = this.#linkIds(event.currentTarget)
        event.currentTarget.classList.add('is-highlighted')
        this.dutchWordTargets.forEach((el) => {
            el.classList.toggle('is-highlighted', ids.includes(el.dataset.wordId))
        })
    }

    unhighlightSource(event) {
        event.currentTarget.classList.remove('is-highlighted')
        this.dutchWordTargets.forEach((el) => el.classList.remove('is-highlighted'))
    }

    highlightDutch(event) {
        const id = event.currentTarget.dataset.wordId
        event.currentTarget.classList.add('is-highlighted')
        this.sourceWordTargets.forEach((el) => {
            el.classList.toggle('is-highlighted', this.#linkIds(el).includes(id))
        })
    }

    unhighlightDutch(event) {
        event.currentTarget.classList.remove('is-highlighted')
        this.sourceWordTargets.forEach((el) => el.classList.remove('is-highlighted'))
    }

    #linkIds(el) {
        return (el.dataset.linkIds || '').split(',').filter(Boolean)
    }
}
