// assets/controllers/strongs_select_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['input', 'translation']

    navigate() {
        const val = this.inputTarget.value.trim().toUpperCase()
        if (!val) return

        // Normalise: H430 → H0430, G316 → G0316
        const normalised = val.replace(/^([HG])(\d+)([A-Z]?)$/, (_, prefix, num, suffix) => {
            const padded = prefix === 'H'
                ? num.padStart(4, '0')
                : num.padStart(4, '0')
            return `${prefix}${padded}${suffix}`
        })

        const translation = this.hasTranslationTarget ? this.translationTarget.value : 'SV'

        window.location.href = `/link/strongs/${encodeURIComponent(translation)}/${encodeURIComponent(normalised)}`
    }
}
