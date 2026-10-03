const MESSAGE_ID = 'app-confirm-message';

let restoreFocus = null;
let onConfirm = null;
let onCancel = null;
let accepting = false;
let bound = false;

function dialogEl() {
    return document.getElementById('app-confirm-dialog');
}

function cancelButton() {
    return document.getElementById('app-confirm-cancel');
}

function acceptButton() {
    return document.getElementById('app-confirm-accept');
}

function bindOnce() {
    if (bound) {
        return;
    }
    bound = true;
    const dialog = dialogEl();
    const cancel = cancelButton();
    const accept = acceptButton();
    if (!(dialog instanceof HTMLDialogElement) || !(cancel instanceof HTMLButtonElement) || !(accept instanceof HTMLButtonElement)) {
        return;
    }
    cancel.addEventListener('click', () => dismiss('cancel'));
    accept.addEventListener('click', () => acceptPending());
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        dismiss('cancel');
    });
}

function resetButtons() {
    const accept = acceptButton();
    if (accept instanceof HTMLButtonElement) {
        accept.disabled = false;
    }
}

function dismiss(reason) {
    const dialog = dialogEl();
    if (!(dialog instanceof HTMLDialogElement) || !dialog.open) {
        return;
    }
    const cancelHandler = onCancel;
    onConfirm = null;
    onCancel = null;
    accepting = false;
    dialog.close();
    resetButtons();
    const target = restoreFocus;
    restoreFocus = null;
    if (reason === 'cancel' && typeof cancelHandler === 'function') {
        cancelHandler();
    }
    if (target instanceof HTMLElement) {
        target.focus();
    }
}

function acceptPending() {
    if (accepting) {
        return;
    }
    accepting = true;
    const accept = acceptButton();
    if (accept instanceof HTMLButtonElement) {
        accept.disabled = true;
    }
    const confirmHandler = onConfirm;
    onConfirm = null;
    onCancel = null;
    const dialog = dialogEl();
    if (dialog instanceof HTMLDialogElement && dialog.open) {
        dialog.close();
    }
    resetButtons();
    restoreFocus = null;
    if (typeof confirmHandler === 'function') {
        confirmHandler();
    }
    accepting = false;
}

export function isConfirmDialogOpen() {
    const dialog = dialogEl();

    return dialog instanceof HTMLDialogElement && dialog.open;
}

export function openConfirmDialog({ message, onConfirm: confirmHandler, onCancel: cancelHandler, restoreFocus: focusEl }) {
    bindOnce();
    const dialog = dialogEl();
    const body = document.getElementById(MESSAGE_ID);
    const cancel = cancelButton();
    if (!(dialog instanceof HTMLDialogElement) || !(body instanceof HTMLElement) || !(cancel instanceof HTMLButtonElement)) {
        return false;
    }
    if (dialog.open) {
        return false;
    }
    body.textContent = typeof message === 'string' && message !== '' ? message : 'Bu işlemi onaylıyor musunuz?';
    restoreFocus = focusEl instanceof HTMLElement ? focusEl : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    onConfirm = typeof confirmHandler === 'function' ? confirmHandler : null;
    onCancel = typeof cancelHandler === 'function' ? cancelHandler : null;
    accepting = false;
    resetButtons();
    dialog.showModal();
    cancel.focus();

    return true;
}
