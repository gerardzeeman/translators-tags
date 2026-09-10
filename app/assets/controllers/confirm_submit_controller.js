import { Controller } from '@hotwired/stimulus'
import { confirmDialog } from '../confirm_dialog.js'

// Generieke vervanger voor <form onsubmit="return confirm(...)"> — toont de
// gestileerde .confirm-dialog i.p.v. de kale browser-confirm(), voor elke
// destructieve actie (bijv. een gebruiker verwijderen).
export default class extends Controller {
    static values = { message: String, confirmLabel: String }

    #confirmed = false

    async submit(event) {
        if (this.#confirmed) return // dialog al doorlopen, laat de echte submit door

        event.preventDefault()
        const ok = await confirmDialog(this.messageValue, {
            confirmLabel: this.hasConfirmLabelValue ? this.confirmLabelValue : 'Verwijderen',
        })
        if (ok) {
            this.#confirmed = true
            this.element.requestSubmit()
        }
    }
}
