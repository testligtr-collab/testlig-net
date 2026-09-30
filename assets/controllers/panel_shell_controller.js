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
        this.placeNav();
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
                first.focus({ preventScroll: true });
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

    placeNav() {
        const nav = this.element.querySelector('.panel-sidebar__nav');
        if (!(nav instanceof HTMLElement)) {
            return;
        }
        nav.scrollTop = 0;
        const current = nav.querySelector('[aria-current="page"]');
        if (!(current instanceof HTMLElement)) {
            return;
        }
        const navBox = nav.getBoundingClientRect();
        const linkBox = current.getBoundingClientRect();
        if (linkBox.top < navBox.top || linkBox.bottom > navBox.bottom) {
            current.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    setMenuExpanded(open) {
        const trigger = this.element.querySelector('.admin-menu-btn');
        if (trigger instanceof HTMLElement) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }
}
