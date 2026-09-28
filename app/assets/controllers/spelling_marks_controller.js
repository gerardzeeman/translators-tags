import { Controller } from '@hotwired/stimulus'

// Marks the words the spelling rules changed in the modern-spelling column
// of Los's commentary (and shows Los's form + the rule on hover) -- or not.
// Remembered per browser: someone who works on the rules wants the marks on
// every chapter. Storage may be unavailable (private window): then just for
// this page.
const KEY = 'alefomega.spellingMarks'

export default class extends Controller {
    static targets = ['button']

    connect() {
        let on = false
        try { on = localStorage.getItem(KEY) === 'on' } catch { /* no storage */ }
        this.#set(on)
    }

    toggle() {
        const on = document.documentElement.dataset.spellingMarks !== 'on'
        this.#set(on)
        try { localStorage.setItem(KEY, on ? 'on' : 'off') } catch { /* no storage */ }
    }

    #set(on) {
        document.documentElement.dataset.spellingMarks = on ? 'on' : 'off'
        if (this.hasButtonTarget) this.buttonTarget.setAttribute('aria-pressed', on ? 'true' : 'false')
    }
}
