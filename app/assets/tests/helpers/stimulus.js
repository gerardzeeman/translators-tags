/**
 * Minimal Stimulus test harness for jsdom.
 *
 */

import { Application } from '@hotwired/stimulus'

/**
 * Create a DOM element from an HTML string and attach it to document.body.
 * @param {string} html
 * @returns {HTMLElement}
 */
export function fixtureElement(html) {
    const wrapper = document.createElement('div')
    wrapper.innerHTML = html.trim()
    const el = wrapper.firstElementChild
    document.body.appendChild(el)
    return el
}

/**
 * Start a Stimulus Application with a single registered controller,
 * then wait one microtask tick for connect() to run.
 *
 * @param {string} identifier  — e.g. 'source-word'
 * @param {typeof import('@hotwired/stimulus').Controller} ControllerClass
 * @param {HTMLElement} [root]  — optional root element (defaults to document.body)
 * @returns {Promise<Application>}
 */
export async function startController(identifier, ControllerClass, root = document.body) {
    const app = Application.start(root)
    app.register(identifier, ControllerClass)
    // Allow the MutationObserver inside Stimulus to fire and call connect()
    await new Promise(resolve => setTimeout(resolve, 0))
    return app
}

/**
 * Fire a native DOM event on an element.
 * @param {HTMLElement} el
 * @param {string} type
 */
export function fireEvent(el, type) {
    el.dispatchEvent(new Event(type, { bubbles: true }))
}

/**
 * Fire a CustomEvent with optional detail on an element.
 * @param {HTMLElement} el
 * @param {string} type
 * @param {object} detail
 */
export function fireCustomEvent(el, type, detail = {}) {
    el.dispatchEvent(new CustomEvent(type, { bubbles: true, detail }))
}
