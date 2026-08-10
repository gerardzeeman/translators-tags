/**
 * Tests for cross_ref_toggle_controller.js
 *
 * Verifies that clicking the trigger toggles the reference list, closes any
 * other open list first, and closes again on an outside click.
 */

import { startController, fixtureElement } from './helpers/stimulus.js'
import CrossRefToggleController from '../controllers/cross_ref_toggle_controller.js'

const IDENTIFIER = 'cross-ref-toggle'

function buildBadge() {
    return `
        <span data-controller="${IDENTIFIER}">
            <button type="button" data-action="click->${IDENTIFIER}#toggle">†</button>
            <span data-${IDENTIFIER}-target="list" hidden>
                <a href="#">Gen. 1:1</a>
            </span>
        </span>
    `
}

describe('CrossRefToggleController', () => {
    let app, el

    beforeEach(async () => {
        el  = fixtureElement(buildBadge())
        app = await startController(IDENTIFIER, CrossRefToggleController, el)
    })

    afterEach(() => {
        app.stop()
        el.remove()
    })

    it('list starts hidden', () => {
        const list = el.querySelector('[data-cross-ref-toggle-target="list"]')
        expect(list.hasAttribute('hidden')).toBe(true)
    })

    it('reveals the list on trigger click', () => {
        el.querySelector('button').click()

        const list = el.querySelector('[data-cross-ref-toggle-target="list"]')
        expect(list.hasAttribute('hidden')).toBe(false)
    })

    it('hides the list again on a second trigger click', () => {
        const button = el.querySelector('button')
        button.click()
        button.click()

        const list = el.querySelector('[data-cross-ref-toggle-target="list"]')
        expect(list.hasAttribute('hidden')).toBe(true)
    })

    it('closes an already-open badge when another one opens', async () => {
        const other = fixtureElement(buildBadge())
        const otherApp = await startController(IDENTIFIER, CrossRefToggleController, other)

        el.querySelector('button').click()
        other.querySelector('button').click()

        expect(el.querySelector('[data-cross-ref-toggle-target="list"]').hasAttribute('hidden')).toBe(true)
        expect(other.querySelector('[data-cross-ref-toggle-target="list"]').hasAttribute('hidden')).toBe(false)

        otherApp.stop()
        other.remove()
    })

    it('closes the list on an outside click', () => {
        el.querySelector('button').click()
        document.body.click()

        const list = el.querySelector('[data-cross-ref-toggle-target="list"]')
        expect(list.hasAttribute('hidden')).toBe(true)
    })

    it('does not close immediately from the same click that opened it', () => {
        // toggle() calls event.stopPropagation(), so the click that opens the
        // list must not also trigger the outside-click listener registered by
        // that same toggle() call.
        el.querySelector('button').click()

        const list = el.querySelector('[data-cross-ref-toggle-target="list"]')
        expect(list.hasAttribute('hidden')).toBe(false)
    })
})
