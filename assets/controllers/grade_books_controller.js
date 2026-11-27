import { Controller } from '@hotwired/stimulus';

/**
 * Grade book tabs for the public homepage. Every grade panel is rendered by the server
 * (real catalog link or "hazırlanıyor" state); this controller only switches which panel is shown.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

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
        const controls = tab.getAttribute('aria-controls');
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.id !== controls;
        });
    }

    syncFromSelected() {
        const selected = this.tabTargets.find((tab) => tab.getAttribute('aria-selected') === 'true')
            ?? this.tabTargets[0];
        if (selected) {
            this.applySelection(selected);
        }
    }
}
