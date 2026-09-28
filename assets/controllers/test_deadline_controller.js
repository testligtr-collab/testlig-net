import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const end = Date.parse(this.element.dataset.deadline || '');
        if (!end) {
            return;
        }
        const tick = () => {
            if (Date.now() >= end) {
                window.location.reload();
                return;
            }
            this.timer = window.setTimeout(tick, 1000);
        };
        tick();
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }
}
