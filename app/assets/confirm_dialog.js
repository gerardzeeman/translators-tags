// Vervangt de kale browser-confirm() door een in-stijl <dialog>, zodat
// bevestigingsvragen (gebruiker verwijderen, bulk-koppelingen bevestigen)
// niet meer breken met de rest van de "manuscript"-vormgeving. Native
// <dialog>.showModal() regelt focus-trap en Escape-to-close automatisch —
// dat hoeft hier niet met eigen JS nagebouwd te worden.
let dialogEl = null

function ensureDialog() {
    if (dialogEl) return dialogEl

    dialogEl = document.createElement('dialog')
    dialogEl.className = 'confirm-dialog'
    dialogEl.innerHTML = `
        <form method="dialog" class="confirm-dialog-form">
            <p class="confirm-dialog-message"></p>
            <div class="confirm-dialog-actions">
                <button type="submit" value="cancel" class="btn-cancel confirm-dialog-cancel"></button>
                <button type="submit" value="confirm" class="confirm-dialog-confirm"></button>
            </div>
        </form>
    `
    document.body.appendChild(dialogEl)
    return dialogEl
}

/**
 * @param {string} message
 * @param {{confirmLabel?: string, cancelLabel?: string, danger?: boolean}} options
 * @returns {Promise<boolean>} true als bevestigd
 */
export function confirmDialog(message, options = {}) {
    const { confirmLabel = 'Bevestigen', cancelLabel = 'Annuleren', danger = true } = options
    const dialog = ensureDialog()

    dialog.querySelector('.confirm-dialog-message').textContent = message

    const confirmBtn = dialog.querySelector('.confirm-dialog-confirm')
    confirmBtn.textContent = confirmLabel
    confirmBtn.className = 'confirm-dialog-confirm ' + (danger ? 'btn-danger' : 'btn-save')
    dialog.querySelector('.confirm-dialog-cancel').textContent = cancelLabel

    return new Promise((resolve) => {
        const onClose = () => {
            dialog.removeEventListener('close', onClose)
            resolve(dialog.returnValue === 'confirm')
        }
        dialog.addEventListener('close', onClose)
        dialog.showModal()
    })
}
