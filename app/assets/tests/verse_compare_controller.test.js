/**
 * Tests for verse_compare_controller.js
 *
 * Covers:
 *  - Translation switcher (switchTranslation action)
 *  - Source-word click highlighting in both Dutch panels
 *  - Dutch-word click back-highlights the source word and cross-panel words
 *  - Hover / unhover (blocked by click-lock)
 *  - clearAll resets all highlight classes
 *  - turbo:frame-load re-applies active translation state
 */

import { startController, fixtureElement } from './helpers/stimulus.js'
import VerseCompareController from '../controllers/verse_compare_controller.js'

const IDENTIFIER = 'verse-compare'

// ── DOM fixture builder ───────────────────────────────────────────────────────
//
// Structure mirrors the actual Twig template:
//   .verse-compare-wrapper[data-controller="verse-compare"]
//     .verse-compare-grid[data-active-translation]
//       .compare-col-left
//         .panel-source
//           span[data-source-id][data-linked-sv-ids][data-linked-hsv-ids]
//       .compare-col-right
//         .panel-sv
//           span[data-tw-id]
//         .panel-hsv
//           span[data-tw-id]
//     .translation-indicator-toggle
//       button[data-translation="SV"]
//       button[data-translation="HSV"]

function buildFixture({
    activeTrans = 'SV',
    words = [
        { sourceId: '1', linkedSvIds: '10,11', linkedHsvIds: '20' },
        { sourceId: '2', linkedSvIds: '12',    linkedHsvIds: '21' },
    ],
    svWords  = [{ twId: '10' }, { twId: '11' }, { twId: '12' }],
    hsvWords = [{ twId: '20' }, { twId: '21' }],
} = {}) {
    const sourceSpans = words.map(w =>
        `<span data-source-id="${w.sourceId}"
               data-linked-sv-ids="${w.linkedSvIds}"
               data-linked-hsv-ids="${w.linkedHsvIds}"
               data-lang="HE"
               data-strongs="H1">word</span>`
    ).join('\n')

    const svSpans  = svWords.map(w => `<span data-tw-id="${w.twId}">sv-word</span>`).join('\n')
    const hsvSpans = hsvWords.map(w => `<span data-tw-id="${w.twId}">hsv-word</span>`).join('\n')

    return fixtureElement(`
        <div data-controller="${IDENTIFIER}">
            <div class="verse-compare-grid" data-active-translation="${activeTrans}">
                <div class="compare-col compare-col-left">
                    <div class="panel-source">
                        ${sourceSpans}
                    </div>
                </div>
                <div class="compare-col compare-col-right">
                    <div class="panel-sv">${svSpans}</div>
                    <div class="panel-hsv">${hsvSpans}</div>
                </div>
            </div>
            <div class="translation-indicator-toggle">
                <button data-action="click->${IDENTIFIER}#switchTranslation"
                        data-translation="SV">SV</button>
                <button data-action="click->${IDENTIFIER}#switchTranslation"
                        data-translation="HSV">HSV</button>
            </div>
        </div>
    `)
}

// Helper: fire a bubbling CustomEvent on el
function fire(el, type, detail = {}) {
    el.dispatchEvent(new CustomEvent(type, { bubbles: true, detail }))
}

// ─────────────────────────────────────────────────────────────────────────────

describe('VerseCompareController', () => {
    let app, el

    beforeEach(async () => {
        el  = buildFixture()
        app = await startController(IDENTIFIER, VerseCompareController, el)
    })

    afterEach(() => {
        app.stop()
        el.remove()
    })

    // ── Initial state ─────────────────────────────────────────────────────────

    it('reads active translation from DOM on connect', () => {
        const grid = el.querySelector('.verse-compare-grid')
        expect(grid.dataset.activeTranslation).toBe('SV')
    })

    it('marks the SV button as active on connect', () => {
        const svBtn = el.querySelector('[data-translation="SV"]')
        expect(svBtn.classList.contains('trans-btn-active')).toBe(true)
    })

    it('does not mark the HSV button as active on connect', () => {
        const hsvBtn = el.querySelector('[data-translation="HSV"]')
        expect(hsvBtn.classList.contains('trans-btn-active')).toBe(false)
    })

    it('picks up HSV as active translation when DOM says HSV', async () => {
        el.remove()
        app.stop()

        el  = buildFixture({ activeTrans: 'HSV' })
        app = await startController(IDENTIFIER, VerseCompareController, el)

        const grid = el.querySelector('.verse-compare-grid')
        expect(grid.dataset.activeTranslation).toBe('HSV')

        const hsvBtn = el.querySelector('[data-translation="HSV"]')
        expect(hsvBtn.classList.contains('trans-btn-active')).toBe(true)
    })

    // ── switchTranslation ─────────────────────────────────────────────────────

    it('switches active-translation attribute on button click', () => {
        const hsvBtn = el.querySelector('[data-translation="HSV"]')
        hsvBtn.click()

        const grid = el.querySelector('.verse-compare-grid')
        expect(grid.dataset.activeTranslation).toBe('HSV')
    })

    it('moves trans-btn-active to the clicked button', () => {
        const svBtn  = el.querySelector('[data-translation="SV"]')
        const hsvBtn = el.querySelector('[data-translation="HSV"]')

        hsvBtn.click()

        expect(hsvBtn.classList.contains('trans-btn-active')).toBe(true)
        expect(svBtn.classList.contains('trans-btn-active')).toBe(false)
    })

    it('does nothing when the already-active button is clicked again', () => {
        const svBtn = el.querySelector('[data-translation="SV"]')
        const grid  = el.querySelector('.verse-compare-grid')

        svBtn.click()   // SV is already active

        expect(grid.dataset.activeTranslation).toBe('SV')
    })

    it('clears all highlights when switching translation', () => {
        // First activate a source word to add highlight classes
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '10,11', hsv: '20' },
        })

        // Now switch translation — should clear everything
        el.querySelector('[data-translation="HSV"]').click()

        expect(srcEl.classList.contains('active')).toBe(false)
        const svWord = el.querySelector('[data-tw-id="10"]')
        expect(svWord.classList.contains('highlighted')).toBe(false)
    })

    // ── source-word:activate highlighting ────────────────────────────────────

    it('adds "active" class to the activated source word', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '10,11', hsv: '20' },
        })

        expect(srcEl.classList.contains('active')).toBe(true)
    })

    it('highlights linked SV words on source activate', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '10,11', hsv: '' },
        })

        expect(el.querySelector('[data-tw-id="10"]').classList.contains('highlighted')).toBe(true)
        expect(el.querySelector('[data-tw-id="11"]').classList.contains('highlighted')).toBe(true)
    })

    it('highlights linked HSV words on source activate', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '', hsv: '20' },
        })

        expect(el.querySelector('[data-tw-id="20"]').classList.contains('highlighted')).toBe(true)
    })

    it('highlights words in both panels simultaneously on source activate', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '10', hsv: '20' },
        })

        expect(el.querySelector('[data-tw-id="10"]').classList.contains('highlighted')).toBe(true)
        expect(el.querySelector('[data-tw-id="20"]').classList.contains('highlighted')).toBe(true)
    })

    it('does not highlight words from the other source word', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: '10', hsv: '20' },
        })

        // twId 12 belongs to source word 2 — must NOT be highlighted
        expect(el.querySelector('[data-tw-id="12"]').classList.contains('highlighted')).toBe(false)
    })

    it('clears previous highlights when a new source word is activated', () => {
        const src1 = el.querySelector('[data-source-id="1"]')
        const src2 = el.querySelector('[data-source-id="2"]')

        fire(src1, 'source-word:activate', { sourceId: '1', linkedIds: { sv: '10', hsv: '20' } })
        fire(src2, 'source-word:activate', { sourceId: '2', linkedIds: { sv: '12', hsv: '21' } })

        // First word's highlights must be cleared
        expect(el.querySelector('[data-tw-id="10"]').classList.contains('highlighted')).toBe(false)
        expect(src1.classList.contains('active')).toBe(false)

        // Second word's highlights are set
        expect(el.querySelector('[data-tw-id="12"]').classList.contains('highlighted')).toBe(true)
        expect(src2.classList.contains('active')).toBe(true)
    })

    // ── dutch-word:activate highlighting ─────────────────────────────────────

    it('adds "highlighted" to the clicked Dutch word', () => {
        const twEl = el.querySelector('[data-tw-id="10"]')
        fire(twEl, 'dutch-word:activate', { twId: '10' })

        expect(twEl.classList.contains('highlighted')).toBe(true)
    })

    it('adds "active" to the source word linked to the Dutch word', () => {
        // twId 10 is linked to sourceId 1 (data-linked-sv-ids="10,11")
        const twEl  = el.querySelector('[data-tw-id="10"]')
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(twEl, 'dutch-word:activate', { twId: '10' })

        expect(srcEl.classList.contains('active')).toBe(true)
    })

    it('also highlights the HSV counterpart via the source word links', () => {
        // sourceId 1 has linkedHsvIds="20" — clicking sv twId 10 should also
        // highlight hsv twId 20 because they share the same source word.
        const twEl  = el.querySelector('[data-tw-id="10"]')
        const hsvEl = el.querySelector('[data-tw-id="20"]')
        fire(twEl, 'dutch-word:activate', { twId: '10' })

        expect(hsvEl.classList.contains('highlighted')).toBe(true)
    })

    it('does not highlight source words not linked to the Dutch word', () => {
        const twEl  = el.querySelector('[data-tw-id="10"]')
        const src2  = el.querySelector('[data-source-id="2"]')
        fire(twEl, 'dutch-word:activate', { twId: '10' })

        expect(src2.classList.contains('active')).toBe(false)
    })

    // ── hover (no click-lock) ─────────────────────────────────────────────────

    it('adds hover-active class on source-word:hover when not click-locked', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:hover', {
            sourceId: '1', linkedIds: { sv: '10', hsv: '20' },
        })

        expect(srcEl.classList.contains('hover-active')).toBe(true)
    })

    it('adds hover-highlighted to linked words on source-word:hover', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:hover', {
            sourceId: '1', linkedIds: { sv: '10', hsv: '20' },
        })

        expect(el.querySelector('[data-tw-id="10"]').classList.contains('hover-highlighted')).toBe(true)
        expect(el.querySelector('[data-tw-id="20"]').classList.contains('hover-highlighted')).toBe(true)
    })

    it('clears hover classes on source-word:unhover', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:hover',   { sourceId: '1', linkedIds: { sv: '10', hsv: '' } })
        fire(srcEl, 'source-word:unhover', {})

        expect(srcEl.classList.contains('hover-active')).toBe(false)
        expect(el.querySelector('[data-tw-id="10"]').classList.contains('hover-highlighted')).toBe(false)
    })

    it('does not apply hover highlights when click-locked', () => {
        // click-lock is set by source-word:activate
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', { sourceId: '1', linkedIds: { sv: '10', hsv: '' } })

        // Now hover a different element — should be ignored
        const src2 = el.querySelector('[data-source-id="2"]')
        fire(src2, 'source-word:hover', { sourceId: '2', linkedIds: { sv: '12', hsv: '' } })

        expect(src2.classList.contains('hover-active')).toBe(false)
    })

    it('does not clear hover classes on unhover when click-locked', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', { sourceId: '1', linkedIds: { sv: '10', hsv: '' } })

        // "active" was set by activate — unhover must not clear it
        fire(srcEl, 'source-word:unhover', {})

        expect(srcEl.classList.contains('active')).toBe(true)
    })

    // ── dutch hover ────────────────────────────────────────────────────────────

    it('adds hover-highlighted on dutch-word:hover when not click-locked', () => {
        const twEl = el.querySelector('[data-tw-id="10"]')
        fire(twEl, 'dutch-word:hover', { twId: '10' })

        expect(twEl.classList.contains('hover-highlighted')).toBe(true)
    })

    it('clears hover classes on dutch-word:unhover', () => {
        const twEl = el.querySelector('[data-tw-id="10"]')
        fire(twEl, 'dutch-word:hover',   { twId: '10' })
        fire(twEl, 'dutch-word:unhover', {})

        expect(twEl.classList.contains('hover-highlighted')).toBe(false)
    })

    // ── turbo:frame-load ──────────────────────────────────────────────────────

    it('re-applies active translation state after turbo:frame-load', () => {
        // Switch to HSV
        el.querySelector('[data-translation="HSV"]').click()

        // Simulate a frame navigation resetting the grid attribute
        const grid = el.querySelector('.verse-compare-grid')
        grid.dataset.activeTranslation = 'SV'   // simulated DOM reset by Turbo

        // Fire frame-load
        document.dispatchEvent(new CustomEvent('turbo:frame-load'))

        // Controller should restore HSV
        expect(grid.dataset.activeTranslation).toBe('HSV')
    })

    it('re-marks the correct button after turbo:frame-load', () => {
        el.querySelector('[data-translation="HSV"]').click()

        // Simulate DOM reset
        el.querySelectorAll('[data-translation]').forEach(btn =>
            btn.classList.remove('trans-btn-active')
        )

        document.dispatchEvent(new CustomEvent('turbo:frame-load'))

        expect(el.querySelector('[data-translation="HSV"]').classList.contains('trans-btn-active')).toBe(true)
        expect(el.querySelector('[data-translation="SV"]').classList.contains('trans-btn-active')).toBe(false)
    })

    // ── id splitting edge cases ───────────────────────────────────────────────

    it('handles whitespace in linked-ids gracefully', async () => {
        el.remove()
        app.stop()

        el  = buildFixture({
            words: [{ sourceId: '1', linkedSvIds: ' 10 , 11 ', linkedHsvIds: '' }],
            svWords: [{ twId: '10' }, { twId: '11' }],
            hsvWords: [],
        })
        app = await startController(IDENTIFIER, VerseCompareController, el)

        const srcEl = el.querySelector('[data-source-id="1"]')
        fire(srcEl, 'source-word:activate', {
            sourceId: '1', linkedIds: { sv: ' 10 , 11 ', hsv: '' },
        })

        expect(el.querySelector('[data-tw-id="10"]').classList.contains('highlighted')).toBe(true)
        expect(el.querySelector('[data-tw-id="11"]').classList.contains('highlighted')).toBe(true)
    })

    it('handles empty linked-ids string without errors', () => {
        const srcEl = el.querySelector('[data-source-id="1"]')
        // Should not throw
        expect(() => {
            fire(srcEl, 'source-word:activate', {
                sourceId: '1', linkedIds: { sv: '', hsv: '' },
            })
        }).not.toThrow()
    })
})
