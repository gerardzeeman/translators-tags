/**
 * Tests for source_word_controller.js
 *
 * Verifies that the controller:
 *  - dispatches source-word:activate / hover / unhover with the correct detail
 *  - registers and deregisters DOM listeners without leaking
 */

import { startController, fixtureElement } from './helpers/stimulus.js'
import SourceWordController from '../controllers/source_word_controller.js'

const IDENTIFIER = 'source-word'

function buildElement(overrides = {}) {
    const attrs = Object.assign({
        'data-controller':     IDENTIFIER,
        'data-source-id':      '42',
        'data-linked-sv-ids':  '10,11',
        'data-linked-hsv-ids': '20',
        'data-lang':           'HE',
        'data-strongs':        'H7225',
    }, overrides)

    const attrStr = Object.entries(attrs)
        .map(([k, v]) => `${k}="${v}"`)
        .join(' ')
    return fixtureElement(`<span ${attrStr}>בְּרֵאשִׁית</span>`)
}

describe('SourceWordController', () => {
    let app, el

    beforeEach(async () => {
        el  = buildElement()
        app = await startController(IDENTIFIER, SourceWordController)
    })

    afterEach(() => {
        app.stop()
        el.remove()
    })

    // ── click dispatches source-word:activate ────────────────────────────────

    it('dispatches source-word:activate on click', () => {
        const received = []
        el.addEventListener('source-word:activate', e => received.push(e.detail))

        el.click()

        expect(received).toHaveLength(1)
    })

    it('activate detail contains sourceId', () => {
        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.sourceId).toBe('42')
    })

    it('activate detail contains linkedIds.sv', () => {
        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.linkedIds.sv).toBe('10,11')
    })

    it('activate detail contains linkedIds.hsv', () => {
        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.linkedIds.hsv).toBe('20')
    })

    it('activate detail picks up any data-linked-*-ids attribute generically', async () => {
        el.remove()
        app.stop()

        el  = buildElement({ 'data-linked-svgbs-ids': '30,31' })
        app = await startController(IDENTIFIER, SourceWordController)

        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.linkedIds.svgbs).toBe('30,31')
        expect(detail.linkedIds.sv).toBe('10,11')
        expect(detail.linkedIds.hsv).toBe('20')
    })

    it('activate detail contains lang and strongs', () => {
        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.lang).toBe('HE')
        expect(detail.strongs).toBe('H7225')
    })

    // ── mouseenter dispatches source-word:hover ───────────────────────────────

    it('dispatches source-word:hover on mouseenter', () => {
        const received = []
        el.addEventListener('source-word:hover', e => received.push(e.detail))

        el.dispatchEvent(new Event('mouseenter', { bubbles: true }))

        expect(received).toHaveLength(1)
        expect(received[0].sourceId).toBe('42')
    })

    // ── mouseleave dispatches source-word:unhover ─────────────────────────────

    it('dispatches source-word:unhover on mouseleave', () => {
        const received = []
        el.addEventListener('source-word:unhover', e => received.push(e.detail))

        el.dispatchEvent(new Event('mouseleave', { bubbles: true }))

        expect(received).toHaveLength(1)
    })

    // ── missing linked ids fall back to empty string ──────────────────────────

    it('uses empty string for linkedIds.sv when attribute is empty', async () => {
        el.remove()
        app.stop()

        el  = buildElement({ 'data-linked-sv-ids': '' })
        app = await startController(IDENTIFIER, SourceWordController)

        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.linkedIds.sv).toBe('')
    })

    it('uses empty string for linkedIds.hsv when attribute is empty', async () => {
        el.remove()
        app.stop()

        el  = buildElement({ 'data-linked-hsv-ids': '' })
        app = await startController(IDENTIFIER, SourceWordController)

        let detail
        el.addEventListener('source-word:activate', e => { detail = e.detail })
        el.click()

        expect(detail.linkedIds.hsv).toBe('')
    })

    // ── events bubble up ─────────────────────────────────────────────────────

    it('activate event bubbles to parent', () => {
        const parent = document.createElement('div')
        document.body.appendChild(parent)
        parent.appendChild(el)

        const received = []
        parent.addEventListener('source-word:activate', e => received.push(e))

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
        el.addEventListener('source-word:activate', e => received.push(e))

        el.click()
        expect(received).toHaveLength(0)
    })
})
