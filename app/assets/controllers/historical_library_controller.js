// assets/controllers/historical_library_controller.js
//
// The alignment-library overview page (lexicon/synonym/multi/phrase rules
// promoted from manual links in the review screen) -- just a delete button
// per row, removing the row on success.

import { Controller } from '@hotwired/stimulus'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? ''
}

export default class extends Controller {
    static targets = ['row']
    static values = { deleteUrl: String }

    async delete(event) {
        const row = event.currentTarget.closest('[data-historical-library-target="row"]')
        const kind = row.dataset.kind
        const id = row.dataset.id
        event.currentTarget.disabled = true

        try {
            const resp = await fetch(this.deleteUrlValue, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body:    JSON.stringify({ kind, id }),
            })
            const data = await resp.json()
            if (data.success) {
                row.remove()
            } else {
                event.currentTarget.disabled = false
            }
        } catch {
            event.currentTarget.disabled = false
        }
    }
}
