// Shared helper for verse_compare_controller.js / source_word_controller.js.
// Source words carry one `data-linked-{code}-ids` attribute per translation
// (e.g. data-linked-sv-ids, data-linked-hsv-ids, data-linked-svgbs-ids).
// Reading them generically here means a new translation needs no JS changes
// beyond rendering its own data-linked-*-ids attribute in the template.

export function linkedIdsFromDataset(dataset) {
    const linkedIds = {}
    for (const [key, value] of Object.entries(dataset)) {
        const m = key.match(/^linked(.+)Ids$/)
        if (m) linkedIds[m[1].toLowerCase()] = value || ''
    }
    return linkedIds
}

export function splitIds(idsStr) {
    return (idsStr || '').split(',').map(s => s.trim()).filter(Boolean)
}
