import { Controller } from '@hotwired/stimulus'

/**
 * Boek/hoofdstuk navigatiepaneel.
 *
 * Desktop: 340px slide-in die het content-gebied bedekt.
 * Mobiel: fullscreen overlay.
 *
 * Targets:
 *   panel       — het paneel zelf
 *   overlay     — transparante achtergrond voor klik-buiten-sluiten
 *   bookList    — linkerkolom met boek-knoppen
 *   chapterGrid — rechterkolom waar hoofdstukraster in wordt gegenereerd
 *   trigger     — de navbar-knop (voor aria-expanded)
 */
export default class extends Controller {
    static targets = ['panel', 'overlay', 'bookList', 'chapterGrid', 'trigger']

    // USFM-code en naam van het boek dat nu in de rechterkolom getoond wordt
    #activeUsfm = null
    #activeChapterCount = 0

    connect() {
        // Markeer het huidige boek als actief in de rechterkolom bij laden
        const activeBtn = this.bookListTarget.querySelector('.nav-panel-book-btn.is-active')
        if (activeBtn) {
            this.#activeUsfm         = activeBtn.dataset.usfm
            this.#activeChapterCount = parseInt(activeBtn.dataset.chapters, 10)
        }

        // Sluit op Escape
        this.#onKeyDown = (e) => { if (e.key === 'Escape') this.close() }
        document.addEventListener('keydown', this.#onKeyDown)
    }

    disconnect() {
        document.removeEventListener('keydown', this.#onKeyDown)
    }

    open() {
        this.panelTarget.classList.add('is-open')
        this.overlayTarget.classList.add('is-open')
        this.triggerTarget.setAttribute('aria-expanded', 'true')
        this.panelTarget.setAttribute('aria-hidden', 'false')

        // Scroll het actieve boek in beeld
        const activeBtn = this.bookListTarget.querySelector('.nav-panel-book-btn.is-active')
        activeBtn?.scrollIntoView({ block: 'nearest' })
    }

    close() {
        this.panelTarget.classList.remove('is-open')
        this.overlayTarget.classList.remove('is-open')
        this.triggerTarget.setAttribute('aria-expanded', 'false')
        this.panelTarget.setAttribute('aria-hidden', 'true')
    }

    selectBook(event) {
        const btn      = event.currentTarget
        const usfm     = btn.dataset.usfm
        const chapters = parseInt(btn.dataset.chapters, 10)
        const name     = btn.dataset.name

        if (usfm === this.#activeUsfm) return

        this.#activeUsfm         = usfm
        this.#activeChapterCount = chapters

        // Highlight geselecteerd boek
        this.bookListTarget
            .querySelectorAll('.nav-panel-book-btn')
            .forEach(b => b.classList.toggle('is-selected', b === btn))

        // Genereer hoofdstukraster
        this.#renderChapterGrid(usfm, name, chapters)
    }

    // ── Private ───────────────────────────────────────────────────────────────

    #onKeyDown = null

    #renderChapterGrid(usfm, name, chapterCount) {
        const grid           = this.chapterGridTarget
        const currentChapter = parseInt(this.triggerTarget.dataset.chapter ?? '0', 10)
        const isCurrentBook  = (usfm === this.triggerTarget.dataset.usfm)
        // Root-element data-url-prefix laat andere pagina's (bijv. Institutio)
        // dit paneel hergebruiken met een ander URL-schema dan /book/{usfm}/{n}.
        const urlPrefix      = this.element.dataset.urlPrefix ?? '/book'

        grid.replaceChildren()

        const heading = document.createElement('div')
        heading.className = 'nav-panel-chapter-book'
        heading.textContent = name   // textContent is XSS-veilig
        grid.appendChild(heading)

        const chapterGrid = document.createElement('div')
        chapterGrid.className = 'nav-panel-chapter-grid'

        Array.from({ length: chapterCount }, (_, i) => {
            const n       = i + 1
            const isActive = isCurrentBook && n === currentChapter
            const a = document.createElement('a')
            a.href      = `${urlPrefix}/${usfm}/${n}`   // usfm/book-id is alphanumeriek, n is integer
            a.className = `nav-chapter-btn${isActive ? ' is-active' : ''}`
            a.dataset.action = 'click->nav-panel#close'
            a.textContent    = String(n)
            chapterGrid.appendChild(a)
        })

        grid.appendChild(chapterGrid)
    }
}
