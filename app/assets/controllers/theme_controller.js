import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['iconLight', 'iconDark']

    connect() {
        const saved = localStorage.getItem('theme') ?? 'light'
        this.#apply(saved)
    }

    toggle() {
        const current = document.documentElement.dataset.theme ?? 'light'
        this.#apply(current === 'dark' ? 'light' : 'dark')
    }

    #apply(theme) {
        document.documentElement.dataset.theme = theme
        localStorage.setItem('theme', theme)

        if (this.hasIconLightTarget) {
            this.iconLightTarget.style.display = theme === 'dark' ? 'none' : ''
            this.iconDarkTarget.style.display  = theme === 'dark' ? '' : 'none'
        }
    }
}
