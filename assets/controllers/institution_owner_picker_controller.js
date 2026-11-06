import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['search', 'value', 'results', 'empty', 'selected', 'label', 'clear'];

    static values = {
        url: String,
    };

    connect() {
        this.timer = 0;
        this.active = -1;
        this.request = null;
        this.onInput = () => this.schedule();
        this.onKeydown = (event) => this.onSearchKeydown(event);
        this.onClear = () => this.clearSelection();
        this.searchTarget.addEventListener('input', this.onInput);
        this.searchTarget.addEventListener('keydown', this.onKeydown);
        this.clearTarget.addEventListener('click', this.onClear);
    }

    disconnect() {
        window.clearTimeout(this.timer);
        if (this.request) {
            this.request.abort();
        }
        this.searchTarget.removeEventListener('input', this.onInput);
        this.searchTarget.removeEventListener('keydown', this.onKeydown);
        this.clearTarget.removeEventListener('click', this.onClear);
    }

    schedule() {
        window.clearTimeout(this.timer);
        const query = this.searchTarget.value.trim();
        if (query.length < 2) {
            this.close();

            return;
        }
        this.timer = window.setTimeout(() => {
            this.search(query);
        }, 250);
    }

    async search(query) {
        if (this.request) {
            this.request.abort();
        }
        this.request = new AbortController();
        try {
            const response = await fetch(this.urlValue + '?q=' + encodeURIComponent(query), {
                headers: { Accept: 'application/json' },
                signal: this.request.signal,
            });
            if (!response.ok) {
                throw new Error('search failed');
            }
            const payload = await response.json();
            const results = Array.isArray(payload.results) ? payload.results.slice(0, 8) : [];
            this.resultsTarget.replaceChildren();
            if (results.length === 0) {
                this.resultsTarget.hidden = true;
                this.emptyTarget.hidden = false;
                this.emptyTarget.textContent = 'Sonuç bulunamadı.';
                this.searchTarget.setAttribute('aria-expanded', 'false');

                return;
            }
            results.forEach((result, index) => {
                if (!result || typeof result.id !== 'string' || typeof result.name !== 'string' || typeof result.email !== 'string') {
                    return;
                }
                const option = document.createElement('li');
                option.id = 'institution-owner-option-' + index;
                option.setAttribute('role', 'option');
                option.tabIndex = -1;
                option.dataset.id = result.id;
                option.dataset.label = result.name + ' · ' + result.email;
                option.textContent = option.dataset.label;
                option.addEventListener('click', () => this.choose(option));
                this.resultsTarget.append(option);
            });
            this.emptyTarget.hidden = true;
            this.resultsTarget.hidden = false;
            this.searchTarget.setAttribute('aria-expanded', 'true');
            this.mark(0);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return;
            }
            this.resultsTarget.hidden = true;
            this.emptyTarget.hidden = false;
            this.emptyTarget.textContent = 'Arama tamamlanamadı.';
        }
    }

    onSearchKeydown(event) {
        const items = this.options();
        if (event.key === 'ArrowDown' && items.length > 0) {
            event.preventDefault();
            this.mark(Math.min(this.active + 1, items.length - 1));
        } else if (event.key === 'ArrowUp' && items.length > 0) {
            event.preventDefault();
            this.mark(Math.max(this.active - 1, 0));
        } else if (event.key === 'Enter' && items[this.active] instanceof HTMLElement) {
            event.preventDefault();
            this.choose(items[this.active]);
        } else if (event.key === 'Escape') {
            this.close();
        }
    }

    choose(option) {
        this.valueTarget.value = option.dataset.id || '';
        this.labelTarget.textContent = option.dataset.label || '';
        this.selectedTarget.hidden = false;
        this.searchTarget.value = '';
        this.close();
    }

    clearSelection() {
        this.valueTarget.value = '';
        this.labelTarget.textContent = '';
        this.selectedTarget.hidden = true;
        this.searchTarget.focus();
    }

    close() {
        this.resultsTarget.hidden = true;
        this.emptyTarget.hidden = true;
        this.searchTarget.setAttribute('aria-expanded', 'false');
        this.active = -1;
    }

    mark(index) {
        const items = this.options();
        this.active = index;
        items.forEach((item, itemIndex) => {
            if (itemIndex === index) {
                item.classList.add('is-active');
                item.setAttribute('aria-selected', 'true');
                this.searchTarget.setAttribute('aria-activedescendant', item.id);
            } else {
                item.classList.remove('is-active');
                item.setAttribute('aria-selected', 'false');
            }
        });
    }

    options() {
        return [...this.resultsTarget.querySelectorAll('[role="option"]')];
    }
}
