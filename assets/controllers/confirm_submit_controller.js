import { Controller } from '@hotwired/stimulus';
import { isConfirmDialogOpen, openConfirmDialog } from '../confirm_dialog.js';

export default class extends Controller {
    static values = { message: String };

    initialize() {
        this.bypass = false;
        this.locked = false;
    }

    submit(event) {
        if (this.bypass) {
            this.bypass = false;
            this.locked = true;

            return;
        }

        event.preventDefault();
        if (this.locked || isConfirmDialogOpen()) {
            return;
        }

        const form = this.element;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
        const opened = openConfirmDialog({
            message: this.messageValue,
            restoreFocus: submitter,
            onConfirm: () => {
                this.bypass = true;
                this.locked = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement ? submitter : undefined);
                } else {
                    this.bypass = false;
                    form.submit();
                }
            },
            onCancel: () => {
                this.locked = false;
            },
        });
        if (!opened) {
            this.locked = false;
        }
    }
}
