import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.dirty = false;
        this.list = this.element.querySelector('#option-list');
        this.onUnload = (event) => {
            if (!this.dirty) {
                return;
            }
            event.preventDefault();
        };
        this.element.addEventListener('input', () => {
            this.dirty = true;
        });
        this.element.addEventListener('submit', () => {
            this.dirty = false;
        });
        window.addEventListener('beforeunload', this.onUnload);
        this.element.querySelector('#add-option')?.addEventListener('click', () => this.add());
        this.list?.addEventListener('click', (event) => this.remove(event));
        this.renumber();
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.onUnload);
    }

    rows() {
        return this.list ? this.list.querySelectorAll('.option-row') : [];
    }

    renumber() {
        this.rows().forEach((row, index) => {
            const radio = row.querySelector('input[type="radio"]');
            if (radio) {
                radio.value = String(index + 1);
            }
            const letter = row.querySelector('.admin-question-option__letter');
        if (letter) {
            letter.textContent = 'ABCDEF'.charAt(index);
        }
        const surface = row.querySelector('[data-editor-surface]');
        if (surface) {
            surface.setAttribute('aria-label', `Seçenek ${index + 1}`);
        }
        const remove = row.querySelector('[data-remove-option]');
            if (remove) {
                remove.disabled = this.rows().length <= 2;
            }
        });
        this.syncImageTargets();
    }

    syncImageTargets() {
        const select = this.element.querySelector('#question-image-target');
        if (!select) {
            return;
        }
        const selected = select.value;
        const kept = Array.from(select.options).filter((option) => option.value === 'stem' || option.value === 'explanation');
        select.replaceChildren(...kept);
        this.rows().forEach((row, index) => {
            const option = document.createElement('option');
            option.value = `option:${index + 1}`;
            option.textContent = `Seçenek ${'ABCDEF'.charAt(index)}`;
            select.appendChild(option);
        });
        if (Array.from(select.options).some((option) => option.value === selected)) {
            select.value = selected;
        }
    }

    add() {
        if (!this.list || this.rows().length >= 6) {
            return;
        }
        const first = this.rows()[0];
        const copy = first.cloneNode(true);
        const area = copy.querySelector('textarea');
        const radio = copy.querySelector('input[type="radio"]');
        const surface = copy.querySelector('[data-editor-surface]');
        const documentField = copy.querySelector('input[name="options_document[]"]');
        if (area) {
            area.value = '';
        }
        if (surface) {
            surface.replaceChildren(document.createElement('div'));
        }
        if (documentField) {
            documentField.value = '';
        }
        copy.removeAttribute('data-initial-document');
        if (radio) {
            radio.checked = false;
        }
        this.list.appendChild(copy);
        this.dirty = true;
        this.renumber();
    }

    remove(event) {
        const button = event.target.closest('[data-remove-option]');
        if (!button || !this.list || this.rows().length <= 2) {
            return;
        }
        button.closest('.option-row')?.remove();
        if (!this.list.querySelector('input[type="radio"]:checked') && this.rows()[0]) {
            const radio = this.rows()[0].querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
            }
        }
        this.dirty = true;
        this.renumber();
    }
}
