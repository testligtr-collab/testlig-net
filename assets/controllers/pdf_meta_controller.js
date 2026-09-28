import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const input = this.element.querySelector('#pdf-file');
        const meta = this.element.querySelector('#pdf-file-meta');
        if (!input || !meta) {
            return;
        }
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) {
                meta.textContent = '';
                return;
            }
            const kb = Math.max(1, Math.round(file.size / 1024));
            meta.textContent = file.name + ' · ' + kb + ' KB';
        });
    }
}
