import { Controller } from '@hotwired/stimulus';

/**
 * Demo-only grade book tabs for homepage preview (does not filter real content).
 */
export default class extends Controller {
    static targets = ['tab', 'panel', 'title'];

    connect() {
        this.syncFromSelected();
    }

    select(event) {
        const tab = event.currentTarget;
        if (!(tab instanceof HTMLElement)) {
            return;
        }
        this.applySelection(tab);
    }

    onKeydown(event) {
        const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
        if (!keys.includes(event.key)) {
            return;
        }
        event.preventDefault();
        const tabs = this.tabTargets;
        const current = tabs.findIndex((tab) => tab.getAttribute('aria-selected') === 'true');
        let next = current;
        if (event.key === 'ArrowRight') {
            next = (current + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            next = (current + tabs.length - 1) % tabs.length;
        } else if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = tabs.length - 1;
        }
        this.applySelection(tabs[next]);
        tabs[next].focus();
    }

    applySelection(tab) {
        this.tabTargets.forEach((candidate) => {
            const selected = candidate === tab;
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
            candidate.tabIndex = selected ? 0 : -1;
        });
        const grade = tab.dataset.grade ?? '';
        const suffix = '8' === grade ? ' · LGS' : '';
        this.titleTarget.textContent = `${grade}. Sınıf${suffix}`;
        if (this.hasPanelTarget) {
            this.panelTarget.setAttribute('aria-labelledby', tab.id);
        }
    }

    syncFromSelected() {
        const selected = this.tabTargets.find((tab) => tab.getAttribute('aria-selected') === 'true')
            ?? this.tabTargets[0];
        if (selected) {
            this.applySelection(selected);
        }
    }
}
