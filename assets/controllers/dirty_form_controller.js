import { Controller } from '@hotwired/stimulus';
import { isConfirmDialogOpen, openConfirmDialog } from '../confirm_dialog.js';

export default class extends Controller {
    connect() {
        this.dirty = false;
        this.mark = () => {
            this.dirty = true;
        };
        this.clear = () => {
            this.dirty = false;
        };
        this.onUnload = (event) => {
            if (!this.dirty) {
                return;
            }
            event.preventDefault();
        };
        this.onOtherSubmit = (event) => {
            if (!this.dirty) {
                return;
            }
            const form = event.currentTarget;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (typeof form.dataset.controller === 'string' && form.dataset.controller.includes('confirm-submit')) {
                return;
            }
            if (form.dataset.confirmBypass === '1') {
                form.dataset.confirmBypass = '0';
                this.dirty = false;

                return;
            }
            event.preventDefault();
            if (isConfirmDialogOpen()) {
                return;
            }
            const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
            openConfirmDialog({
                message: 'Kaydedilmemiş değişiklikler var. Yine de devam edilsin mi?',
                restoreFocus: submitter,
                onConfirm: () => {
                    this.dirty = false;
                    form.dataset.confirmBypass = '1';
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement ? submitter : undefined);
                    } else {
                        form.submit();
                    }
                },
            });
        };
        this.element.addEventListener('input', this.mark);
        this.element.addEventListener('change', this.mark);
        this.element.addEventListener('submit', this.clear);
        window.addEventListener('beforeunload', this.onUnload);
        this.others = document.querySelectorAll('form[data-warn-unsaved]');
        this.others.forEach((form) => form.addEventListener('submit', this.onOtherSubmit));
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onUnload);
        this.others?.forEach((form) => form.removeEventListener('submit', this.onOtherSubmit));
    }
}
