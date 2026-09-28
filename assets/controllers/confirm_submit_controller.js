import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { message: String };

    submit(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
