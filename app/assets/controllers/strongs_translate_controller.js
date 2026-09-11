// assets/controllers/strongs_translate_controller.js
//
// Strong's-nummer-selector op /strongs/translate: navigeert naar
// /strongs/translate/{nummer}. Was een los inline <script>-blok zonder CSP-
// nonce -- de site-brede Content-Security-Policy (script-src met nonce,
// geen 'unsafe-inline') blokkeerde die uitvoering stilzwijgend, waardoor de
// "Openen"-knop niets deed.

import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['input']
    static values = { homeUrl: String }

    navigate(event) {
        event.preventDefault()
        const val = this.inputTarget.value.trim().toUpperCase()
        if (val) {
            window.location.href = `${this.homeUrlValue}/${encodeURIComponent(val)}`
        }
    }
}
