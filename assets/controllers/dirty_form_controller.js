import { Controller } from '@hotwired/stimulus';

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
            if (!window.confirm('Kaydedilmemiş değişiklikler var. Yine de devam edilsin mi?')) {
                event.preventDefault();
                return;
            }
            this.dirty = false;
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
