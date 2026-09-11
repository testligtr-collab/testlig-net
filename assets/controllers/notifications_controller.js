import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'panel'];

    connect() {
        this.close();
    }

    toggle() {
        const open = this.buttonTarget.getAttribute('aria-expanded') === 'true';
        if (open) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.panelTarget.hidden = false;
        this.panelTarget.classList.add('is-open');
    }

    close() {
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.panelTarget.hidden = true;
        this.panelTarget.classList.remove('is-open');
    }
}
