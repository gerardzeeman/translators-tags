import { Controller } from '@hotwired/stimulus'

const STORAGE_KEY = 'ao:visible-translations'

export default class extends Controller {
    static values = { translations: Array }

    connect() {
        this.visible = this._loadVisible()
        this._apply()
    }

    toggle(event) {
        const code = event.currentTarget.dataset.translation
        if (event.currentTarget.checked) {
            this.visible.add(code)
        } else {
            if (this.visible.size <= 1) {
                event.currentTarget.checked = true
                return
            }
            this.visible.delete(code)
        }
        this._save()
        this._apply()
    }

    _loadVisible() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY)
            if (saved) {
                const parsed = JSON.parse(saved)
                const filtered = parsed.filter(c => this.translationsValue.includes(c))
                if (filtered.length > 0) return new Set(filtered)
            }
        } catch (_) { /* ignore */ }
        return new Set(this.translationsValue)
    }

    _save() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify([...this.visible]))
    }

    _apply() {
        // Sync checkbox state
        this.element.querySelectorAll('input[data-translation]').forEach(cb => {
            cb.checked = this.visible.has(cb.dataset.translation)
        })

        const grid   = document.querySelector('.chapter-verse-grid')
        const header = document.querySelector('.chapter-grid-header')
        if (!grid || !header) return

        const all     = this.translationsValue
        const visible = all.filter(c => this.visible.has(c))

        // Update CSS classes on grid + header to drive display:none per hidden column
        all.forEach(code => {
            const hidden = !this.visible.has(code)
            grid.classList.toggle(`hide-${code.toLowerCase()}`, hidden)
            header.classList.toggle(`hide-${code.toLowerCase()}`, hidden)
        })

        // Column count follows the currently visible translations exactly — avoids
        // hand-enumerating every hide/role combination in CSS (source col + one
        // 1fr per visible translation).
        const columns = `2fr ${visible.map(() => '1fr').join(' ')}`
        grid.style.gridTemplateColumns   = columns
        header.style.gridTemplateColumns = columns

        // Right border / corner radius belong on the last VISIBLE column only.
        grid.querySelectorAll('.is-last-visible-col').forEach(el => el.classList.remove('is-last-visible-col'))
        header.querySelectorAll('.is-last-visible-col').forEach(el => el.classList.remove('is-last-visible-col'))
        const lastCode = visible[visible.length - 1]
        if (lastCode) {
            const cls = `verse-cell-${lastCode.toLowerCase()}`
            grid.querySelectorAll(`.${cls}`).forEach(el => el.classList.add('is-last-visible-col'))
            const headerCell = header.querySelector(`.col-header-${lastCode.toLowerCase()}`)
            if (headerCell) headerCell.classList.add('is-last-visible-col')
        }

        // Switch active translation if it's now hidden
        const active = grid.dataset.activeTranslation
        if (active && !this.visible.has(active)) {
            const first = visible[0]
            if (first) {
                const btn = document.querySelector(
                    `[data-action*="verse-compare#switchTranslation"][data-translation="${first}"]`
                )
                if (btn) btn.click()
                else grid.dataset.activeTranslation = first
            }
        }
    }
}
