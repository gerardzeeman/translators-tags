// assets/controllers/embed_highlight_controller.js
import { Controller } from '@hotwired/stimulus'

// Self-contained hover-highlight for a single blog embed block: hovering a
// source word highlights its linked translation word(s) (across every
// translation panel shown, if the embed has more than one) and vice versa.
// Unlike source_word/dutch_word_controller.js (which coordinate across a
// multi-translation page via bubbling events), an embed is self-contained --
// its source words carry the union of all shown translations' link ids
// (translation_words.id is globally unique, so a plain union can't
// collide), which is enough for this simpler target-based controller to
// highlight the right word in every panel without being translation-aware.
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

        // Find the source word(s) linked to this word, then highlight every
        // OTHER Dutch word (in any translation panel) that same source word
        // is linked to -- not just the source text, so highlighting a word
        // in one translation cross-highlights the linked word in the rest.
        const linkedIds = new Set()
        this.sourceWordTargets.forEach((el) => {
            const ids = this.#linkIds(el)
            const matches = ids.includes(id)
            el.classList.toggle('is-highlighted', matches)
            if (matches) ids.forEach((linkedId) => linkedIds.add(linkedId))
        })

        this.dutchWordTargets.forEach((el) => {
            el.classList.toggle('is-highlighted', linkedIds.has(el.dataset.wordId))
        })
    }

    unhighlightDutch(event) {
        event.currentTarget.classList.remove('is-highlighted')
        this.sourceWordTargets.forEach((el) => el.classList.remove('is-highlighted'))
        this.dutchWordTargets.forEach((el) => el.classList.remove('is-highlighted'))
    }

    #linkIds(el) {
        return (el.dataset.linkIds || '').split(',').filter(Boolean)
    }
}
