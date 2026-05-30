/**
 * Tests for dutch_word_controller.js
 *
 * Verifies that the controller dispatches dutch-word:activate / hover / unhover
 * with the correct detail payload (twId, method, score).
 */

import { startController, fixtureElement } from './helpers/stimulus.js'
import DutchWordController from '../controllers/dutch_word_controller.js'

const IDENTIFIER = 'dutch-word'

function buildElement(overrides = {}) {
    const attrs = Object.assign({
        'data-controller': IDENTIFIER,
        'data-tw-id':      '101',
        'data-method':     'manual',
        'data-score':      '1',
    }, overrides)

    const attrStr = Object.entries(attrs)
        .map(([k, v]) => `${k}="${v}"`)
        .join(' ')
    return fixtureElement(`<span ${attrStr}>In</span>`)
}

describe('DutchWordController', () => {
    let app, el

    beforeEach(async () => {
        el  = buildElement()
        app = await startController(IDENTIFIER, DutchWordController)
    })

    afterEach(() => {
        app.stop()
        el.remove()
    })

    // ── click dispatches dutch-word:activate ──────────────────────────────────

    it('dispatches dutch-word:activate on click', () => {
        const received = []
        el.addEventListener('dutch-word:activate', e => received.push(e.detail))

        el.click()

        expect(received).toHaveLength(1)
    })

    it('activate detail contains twId', () => {
        let detail
        el.addEventListener('dutch-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.twId).toBe('101')
    })

    it('activate detail contains method and score', () => {
        let detail
        el.addEventListener('dutch-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.method).toBe('manual')
        expect(detail.score).toBe('1')
    })

    // ── mouseenter dispatches dutch-word:hover ────────────────────────────────

    it('dispatches dutch-word:hover on mouseenter', () => {
        const received = []
        el.addEventListener('dutch-word:hover', e => received.push(e.detail))

        el.dispatchEvent(new Event('mouseenter', { bubbles: true }))

        expect(received).toHaveLength(1)
        expect(received[0].twId).toBe('101')
    })

    // ── mouseleave dispatches dutch-word:unhover ──────────────────────────────

    it('dispatches dutch-word:unhover on mouseleave', () => {
        const received = []
        el.addEventListener('dutch-word:unhover', e => received.push(e.detail))

        el.dispatchEvent(new Event('mouseleave', { bubbles: true }))

        expect(received).toHaveLength(1)
    })

    // ── detail reflects current data attributes ───────────────────────────────

    it('detail reflects updated data-tw-id attribute', async () => {
        el.remove()
        app.stop()

        el  = buildElement({ 'data-tw-id': '999', 'data-method': 'auto', 'data-score': '0.75' })
        app = await startController(IDENTIFIER, DutchWordController)

        let detail
        el.addEventListener('dutch-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.twId).toBe('999')
        expect(detail.method).toBe('auto')
        expect(detail.score).toBe('0.75')
    })

    // ── events bubble up ──────────────────────────────────────────────────────

    it('activate event bubbles to parent', () => {
        const parent = document.createElement('div')
        document.body.appendChild(parent)
        parent.appendChild(el)

        const received = []
        parent.addEventListener('dutch-word:activate', e => received.push(e))

        el.click()
        expect(received).toHaveLength(1)

        parent.remove()
    })

    // ── after disconnect, click no longer dispatches ──────────────────────────

    it('does not dispatch after disconnect', async () => {
        // Stimulus calls disconnect() when the element leaves the DOM
        el.remove()
        await new Promise(resolve => setTimeout(resolve, 0))

        const received = []
        el.addEventListener('dutch-word:activate', e => received.push(e))

        el.click()
        expect(received).toHaveLength(0)
    })
})
