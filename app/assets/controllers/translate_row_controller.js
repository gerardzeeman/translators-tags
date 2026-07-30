import { Controller } from '@hotwired/stimulus'

/**
 * Per-rij bewerk/voorstel-flow op /institutie/bewerk/{id} -- één instantie
 * per &lt;form&gt; die een vertaalvoorstel voor precies één rij (of, in de
 * niet-uitgelijnde modus, de hele vertaling) indient. Elk tekstvak begint
 * readonly; "Bewerken" zet het open, "Annuleren" zet het terug naar de
 * oorspronkelijke waarde, "Vertaalvoorstel indienen" opent een popup met de
 * verplichte reden -- pas het indienen daarvan (een gewone submit) stuurt
 * het formulier daadwerkelijk in.
 */
export default class extends Controller {
    static targets = ['textarea', 'editButton', 'cancelButton', 'proposeButton', 'popup', 'reasonField']

    #originalValue = null

    connect() {
        this.#originalValue = this.textareaTarget.value
    }

    edit() {
        this.textareaTarget.readOnly = false
        this.textareaTarget.focus()
        this.editButtonTarget.hidden = true
        this.cancelButtonTarget.hidden = false
        this.proposeButtonTarget.hidden = false
    }

    cancelEdit() {
        this.textareaTarget.value = this.#originalValue
        this.textareaTarget.readOnly = true
        this.editButtonTarget.hidden = false
        this.cancelButtonTarget.hidden = true
        this.proposeButtonTarget.hidden = true
        this.closePopup()
    }

    openPopup() {
        this.popupTarget.classList.add('is-open')
        this.reasonFieldTarget.focus()
    }

    closePopup() {
        this.popupTarget.classList.remove('is-open')
    }

    // Sluit alleen als er echt op de achtergrond (niet op de popup-inhoud)
    // geklikt is -- de klik bubbelt door naar deze listener ongeacht welk
    // element erin geraakt werd, dus alleen event.target === deze
    // achtergrond zelf telt als "buiten de popup geklikt".
    closeOnBackdrop(event) {
        if (event.target === event.currentTarget) {
            this.closePopup()
        }
    }
}
