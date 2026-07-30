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
        this.editButtonTarget.classList.add('is-hidden')
        this.cancelButtonTarget.classList.remove('is-hidden')
        this.proposeButtonTarget.classList.remove('is-hidden')
    }

    cancelEdit() {
        this.textareaTarget.value = this.#originalValue
        this.textareaTarget.readOnly = true
        this.editButtonTarget.classList.remove('is-hidden')
        this.cancelButtonTarget.classList.add('is-hidden')
        this.proposeButtonTarget.classList.add('is-hidden')
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
