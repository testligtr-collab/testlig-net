const rootSelector = '.admin-test-results--detail';

function tailKeys(node) {
    if (node.getAttribute('data-formula-keys') === '1') {
        return;
    }
    node.setAttribute('data-formula-keys', '1');
    node.addEventListener('keydown', (event) => {
        const max = Math.max(0, node.scrollWidth - node.clientWidth);
        if (event.key === 'ArrowRight') {
            node.scrollLeft = Math.min(max, node.scrollLeft + 48);
        } else if (event.key === 'ArrowLeft') {
            node.scrollLeft = Math.max(0, node.scrollLeft - 48);
        } else if (event.key === 'End') {
            node.scrollLeft = max;
        } else if (event.key === 'Home') {
            node.scrollLeft = 0;
        } else {
            return;
        }
        event.preventDefault();
    });
}

function mark() {
    const root = document.querySelector(rootSelector);
    if (!root) {
        return;
    }
    root.querySelectorAll('.q-math').forEach((node) => {
        if (!(node instanceof HTMLElement)) {
            return;
        }
        const overflows = node.scrollWidth > node.clientWidth + 1;
        if (overflows) {
            node.tabIndex = 0;
            node.setAttribute('role', 'group');
            node.setAttribute('aria-label', 'Formül');
            node.setAttribute('data-formula-scroll', '1');
            tailKeys(node);
            return;
        }
        if (node.getAttribute('data-formula-scroll') === '1') {
            node.removeAttribute('tabindex');
            node.removeAttribute('role');
            node.removeAttribute('aria-label');
            node.removeAttribute('data-formula-scroll');
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mark);
} else {
    mark();
}
window.addEventListener('resize', mark);
