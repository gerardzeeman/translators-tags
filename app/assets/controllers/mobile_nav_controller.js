import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['menu', 'hamburger']

    connect() {
        this._onMenuClick = this._handleSubMenuClick.bind(this)
    }

    toggle() {
        const isOpen = this.element.classList.toggle('nav-open')
        this.hamburgerTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false')
        document.body.style.overflow = isOpen ? 'hidden' : ''

        if (isOpen) {
            this.menuTarget.addEventListener('click', this._onMenuClick)
        } else {
            this._closeAllSubMenus()
            this.menuTarget.removeEventListener('click', this._onMenuClick)
        }
    }

    close() {
        this.element.classList.remove('nav-open')
        if (this.hasHamburgerTarget) {
            this.hamburgerTarget.setAttribute('aria-expanded', 'false')
        }
        document.body.style.overflow = ''
        this._closeAllSubMenus()
        if (this.hasMenuTarget) {
            this.menuTarget.removeEventListener('click', this._onMenuClick)
        }
    }

    _handleSubMenuClick(event) {
        const btn = event.target.closest('.nav-dropdown-btn, .nav-user-btn')
        if (!btn) return

        const container = btn.closest('.nav-dropdown, .nav-user, .nav-trans-selector')
        if (!container) return

        event.stopPropagation()

        const isOpen = container.classList.toggle('is-open')
        if (isOpen) {
            // Sluit andere open sub-menu's
            this.menuTarget.querySelectorAll('.nav-dropdown, .nav-user, .nav-trans-selector')
                .forEach(el => { if (el !== container) el.classList.remove('is-open') })
        }
    }

    _closeAllSubMenus() {
        if (this.hasMenuTarget) {
            this.menuTarget.querySelectorAll('.nav-dropdown, .nav-user, .nav-trans-selector')
                .forEach(el => el.classList.remove('is-open'))
        }
    }
}
