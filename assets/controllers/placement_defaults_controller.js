import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const content = this.element.querySelector('#topic-placement-content');
        if (content) {
            content.addEventListener('change', () => this.fillContent(content));
        }
        const topic = this.element.querySelector('#placement-topic');
        if (topic) {
            topic.addEventListener('change', () => this.fillPosition(topic));
        }
    }

    fillContent(select) {
        const option = select.options[select.selectedIndex];
        const title = this.element.querySelector('#topic-placement-title');
        const summary = this.element.querySelector('#topic-placement-summary');
        if (!option || !option.value || !title || !summary) {
            return;
        }
        title.value = option.getAttribute('data-title') || '';
        summary.value = option.getAttribute('data-summary') || '';
    }

    fillPosition(select) {
        const option = select.options[select.selectedIndex];
        const position = this.element.querySelector('#placement-position');
        if (!option || !option.value || !position) {
            return;
        }
        const next = option.getAttribute('data-next-position');
        if (null !== next && '' !== next) {
            position.value = next;
        }
    }
}
