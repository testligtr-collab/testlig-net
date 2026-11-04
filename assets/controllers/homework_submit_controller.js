import { Controller } from '@hotwired/stimulus';
import { counterText } from '../homework_text.js';

/**
 * Student homework body counter (Unicode code points) and submit body sync.
 */
export default class extends Controller {
    static targets = ['body', 'counter', 'submitBody'];

    static values = {
        bodyMax: Number,
    };

    connect() {
        this.refresh();
        const alert = this.element.querySelector('.shw-alert');
        if (alert instanceof HTMLElement) {
            alert.focus({ preventScroll: false });
        }
    }

    refresh() {
        if (this.hasBodyTarget && this.hasCounterTarget) {
            const text = counterText(this.bodyTarget.value, this.bodyMaxValue);
            this.counterTarget.textContent = text;
            const over = Number.parseInt(text, 10) > this.bodyMaxValue;
            this.counterTarget.classList.toggle('shw-counter--over', over);
        }
        this.syncBody();
    }

    syncBody() {
        if (this.hasBodyTarget && this.hasSubmitBodyTarget) {
            this.submitBodyTarget.value = this.bodyTarget.value;
        }
    }
}
