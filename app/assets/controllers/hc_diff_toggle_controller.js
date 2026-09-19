import { Controller } from '@hotwired/stimulus'

// Toggles highlighting of words in the HC's main Latin (1697-traditie)
// column that differ from the editio-princeps-1563 layer. Off by default
// on every fresh visit -- deliberately not persisted to localStorage like
// theme_controller's light/dark toggle, since this is a niche textual-
// comparison aid rather than a display preference someone would want to
// carry across the whole site.
export default class extends Controller {
    static targets = ['button']

    toggle() {
        const root = document.documentElement
        const on = root.dataset.hcDiff === 'on'
        root.dataset.hcDiff = on ? 'off' : 'on'
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-pressed', on ? 'false' : 'true')
        }
    }
}
