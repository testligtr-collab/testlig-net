import { Controller } from '@hotwired/stimulus';

const DESKTOP_MQ = '(min-width: 1024px)';

export default class extends Controller {
    static targets = ['button', 'drawer'];

    connect() {
        this.onKeydown = this.onKeydown.bind(this);
        this.onResize = this.onResize.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        window.addEventListener('resize', this.onResize);
        this.close({ restoreFocus: false });
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        window.removeEventListener('resize', this.onResize);
    }

    toggle() {
        if (this.isOpen()) {
            this.close({ restoreFocus: true });
        } else {
            this.open();
        }
    }

    open() {
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.drawerTarget.hidden = false;
        this.drawerTarget.classList.add('is-open');
    }

    close({ restoreFocus = false } = {}) {
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        this.drawerTarget.hidden = true;
        this.drawerTarget.classList.remove('is-open');
        if (restoreFocus) {
            this.buttonTarget.focus();
        }
    }

    closeOnNavigate(event) {
        if (event.target.closest('a')) {
            this.close({ restoreFocus: false });
        }
    }

    onKeydown(event) {
        if (event.key === 'Escape' && this.isOpen()) {
            this.close({ restoreFocus: true });
        }
    }

    onResize() {
        if (window.matchMedia(DESKTOP_MQ).matches && this.isOpen()) {
            this.close({ restoreFocus: false });
        }
    }

    isOpen() {
        return this.buttonTarget.getAttribute('aria-expanded') === 'true';
    }
}
