import { Controller } from '@hotwired/stimulus'

// Generieke "navigeer naar de URL van de geselecteerde optie" — voor <select>-
// dropdowns die als compacte mobiele vervanging van een knoppenrij-navigatie
// dienen (bijv. de vertaalkeuze op de koppelpagina).
export default class extends Controller {
    navigate(event) {
        const url = event.target.value
        if (url) window.location.href = url
    }
}
