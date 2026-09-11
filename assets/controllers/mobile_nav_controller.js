import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'drawer'];

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
        this.drawerTarget.hidden = false;
        this.drawerTarget.classList.add('is-open');
    }

    close() {
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.drawerTarget.hidden = true;
        this.drawerTarget.classList.remove('is-open');
    }
}
