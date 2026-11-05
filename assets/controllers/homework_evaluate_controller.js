import { Controller } from '@hotwired/stimulus';
import { counterText } from '../homework_text.js';

/**
 * Teacher evaluation feedback counter (Unicode code points, same rule as the server).
 */
export default class extends Controller {
    static targets = ['feedback', 'counter'];

    static values = {
        feedbackMax: Number,
    };

    connect() {
        this.refresh();
        const alert = this.element.querySelector('.hw-alert');
        if (alert instanceof HTMLElement) {
            alert.focus({ preventScroll: false });
        }
    }

    refresh() {
        if (!this.hasFeedbackTarget || !this.hasCounterTarget) {
            return;
        }
        const text = counterText(this.feedbackTarget.value, this.feedbackMaxValue);
        this.counterTarget.textContent = text;
        const over = Number.parseInt(text, 10) > this.feedbackMaxValue;
        this.counterTarget.classList.toggle('hw-counter--over', over);
    }
}
