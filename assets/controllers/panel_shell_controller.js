import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle'];
    static classes = ['collapsed'];

    connect() {
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.element.classList.contains('is-nav-open')) {
                this.closeMenu();
            }
        };
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggle() {
        const collapsed = this.element.classList.toggle(this.collapsedClass);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            this.toggleTarget.textContent = collapsed ? 'Kenar çubuğunu genişlet' : 'Kenar çubuğunu daralt';
        }
    }

    menu(event) {
        const open = this.element.classList.toggle('is-nav-open');
        this.setMenuExpanded(open);
        if (open) {
            const first = this.element.querySelector('.panel-sidebar a');
            if (first instanceof HTMLElement) {
                first.focus();
            }
        }
        event.preventDefault();
    }

    closeMenu() {
        if (!this.element.classList.contains('is-nav-open')) {
            return;
        }
        this.element.classList.remove('is-nav-open');
        const trigger = this.element.querySelector('.admin-menu-btn');
        this.setMenuExpanded(false);
        if (trigger instanceof HTMLElement) {
            trigger.focus();
        }
    }

    setMenuExpanded(open) {
        const trigger = this.element.querySelector('.admin-menu-btn');
        if (trigger instanceof HTMLElement) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }
}
