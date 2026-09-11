import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle'];
    static classes = ['collapsed'];

    toggle() {
        const collapsed = this.element.classList.toggle(this.collapsedClass);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
            this.toggleTarget.textContent = collapsed ? 'Kenar çubuğunu genişlet' : 'Kenar çubuğunu daralt';
        }
    }
}
