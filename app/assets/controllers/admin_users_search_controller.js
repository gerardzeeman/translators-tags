// assets/controllers/admin_users_search_controller.js
//
// Live zoekbalk op /admin/users: filtert client-side op naam of e-mailadres,
// zonder serververzoek. Werkt op de tabelrijen (desktop) en de kaarten
// (mobiel) tegelijk -- welke van de twee zichtbaar is bepaalt de CSS media
// query, deze controller houdt ze allebei gesynchroniseerd zodat filteren
// werkt ongeacht schermbreedte of resize.

import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['input', 'row', 'card', 'empty']

    filter() {
        const query = this.inputTarget.value.trim().toLowerCase()

        const apply = (el) => {
            el.hidden = query !== '' && !el.dataset.search.includes(query)
        }
        this.rowTargets.forEach(apply)
        this.cardTargets.forEach(apply)

        if (this.hasEmptyTarget) {
            const anyVisible = this.rowTargets.some((el) => !el.hidden)
            this.emptyTarget.hidden = anyVisible
        }
    }
}
