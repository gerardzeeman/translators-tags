// assets/controllers/passage_select_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['testament', 'book', 'chapter', 'verse']

    connect() {
        this.#filterBooks()
    }

    onTestamentChange() {
        this.#filterBooks()
        this.chapterTarget.value = 1
        this.verseTarget.value   = 1
    }

    onBookChange() {
        const selected = this.bookTarget.selectedOptions[0]
        const maxChapter = parseInt(selected?.dataset.maxChapter || '150')
        this.chapterTarget.max = maxChapter
        this.chapterTarget.value = 1
        this.verseTarget.value   = 1
    }

    onChapterChange() {
        this.verseTarget.value = 1
    }

    navigate() {
        const usfm    = this.bookTarget.value
        const chapter = this.chapterTarget.value
        const verse   = this.verseTarget.value

        if (usfm && chapter && verse) {
            window.location.href = `/link/passage/${usfm}/${chapter}/${verse}`
        }
    }

    #filterBooks() {
        const testament = this.testamentTarget.value
        const opts = this.bookTarget.querySelectorAll('option')
        let firstVisible = null

        opts.forEach(opt => {
            const show = opt.dataset.testament === testament || !opt.dataset.testament
            opt.style.display = show ? '' : 'none'
            if (show && !firstVisible) firstVisible = opt
        })

        if (firstVisible) {
            this.bookTarget.value = firstVisible.value
            this.onBookChange()
        }
    }
}
