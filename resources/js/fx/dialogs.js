/*
 * Dialogs: [data-fx-dialog-open="id"] opens a native <dialog>; [data-fx-dialog-close] closes the one it is in.
 * Forms that carry data-fx-confirm="Message" ask first, in an accessible dialog, instead of window.confirm().
 */
const confirmDialog = document.getElementById('fx-confirm');
let pendingForm = null;

document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-fx-dialog-open]');
    if (opener) {
        const target = document.getElementById(opener.dataset.fxDialogOpen);
        if (target && typeof target.showModal === 'function') {
            event.preventDefault();
            target.showModal();
        }
        return;
    }

    const closer = event.target.closest('[data-fx-dialog-close]');
    if (closer) {
        closer.closest('dialog')?.close();
        return;
    }

    if (event.target instanceof HTMLDialogElement && event.target.dataset.fxBackdrop !== 'off') {
        event.target.close();
    }
});

if (confirmDialog && typeof confirmDialog.showModal === 'function') {
    const message = confirmDialog.querySelector('[data-confirm-message]');
    const go = confirmDialog.querySelector('[data-confirm-go]');

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.fxConfirm || form.dataset.fxConfirmed === '1') return;

        event.preventDefault();
        pendingForm = form;
        message.textContent = form.dataset.fxConfirm;
        go.textContent = form.dataset.fxConfirmLabel || 'Confirm';
        confirmDialog.showModal();
        go.focus();
    });

    go.addEventListener('click', () => {
        const form = pendingForm;
        pendingForm = null;
        confirmDialog.close();
        if (form) {
            form.dataset.fxConfirmed = '1';
            form.requestSubmit();
        }
    });

    confirmDialog.addEventListener('close', () => {
        pendingForm = null;
    });
}
