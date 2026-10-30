import { Controller } from '@hotwired/stimulus';

const ALLOWED = new Set(['DIV', 'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'SPAN']);

export default class extends Controller {
    connect() {
        this.history = new WeakMap();
        this.syncing = false;
        this.previewBound = new WeakSet();
        this.element.querySelectorAll('[data-editor-field]').forEach((field) => this.bind(field));
        this.bindPreviews(this.element);
        this.element.addEventListener('submit', () => this.syncAll());
        this.element.addEventListener('question-editor:bind', (event) => {
            const field = event.detail?.field;
            if (field instanceof HTMLElement) {
                this.bind(field);
                this.bindPreviews(field);
            }
        });
    }

    bindPreviews(root) {
        root.querySelectorAll('[data-image-file]').forEach((file) => {
            if (this.previewBound.has(file)) {
                return;
            }
            this.previewBound.add(file);
            const frame = file.closest('.admin-question-image-box')?.querySelector('[data-image-preview]');
            file.addEventListener('change', () => this.previewFile(file, frame));
        });
    }

    bind(field) {
        const surface = field.querySelector('[data-editor-surface]');
        const fallback = field.querySelector('textarea');
        if (!surface) {
            return;
        }
        const initial = field.getAttribute('data-initial-document') || '';
        if (initial) {
            try {
                this.render(surface, JSON.parse(initial));
            } catch {
                this.renderPlain(surface, fallback?.value || '');
            }
        } else {
            this.renderPlain(surface, fallback?.value || '');
        }
        this.remember(field);
        this.wireTabs(field);
        surface.addEventListener('focusout', () => this.keepRange(surface));
        surface.addEventListener('beforeinput', (event) => {
            if (event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop') {
                event.preventDefault();
                const text = event.dataTransfer?.getData('text/plain') || event.data || '';
                this.insertText(surface, text.replace(/[<>]/g, ''));
                this.afterEdit(field);
            }
        });
        surface.addEventListener('input', () => {
            this.clean(surface);
            this.afterEdit(field);
        });
        field.querySelector('.q-toolbar')?.addEventListener('mousedown', (event) => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        });
        field.querySelector('[data-editor-science]')?.addEventListener('mousedown', (event) => {
            if (event.target.closest('button')) {
                this.keepRange(surface);
                event.preventDefault();
            }
        });
        field.addEventListener('click', (event) => this.command(event, field));
        fallback?.addEventListener('input', () => {
            if (this.syncing) {
                return;
            }
            this.renderPlain(surface, fallback.value);
            this.remember(field);
        });
    }

    command(event, field) {
        const button = event.target.closest('button');
        if (!button || !field.contains(button)) {
            return;
        }
        const surface = field.querySelector('[data-editor-surface]');
        if (!surface || button.hasAttribute('data-editor-tab')) {
            return;
        }
        const outside = document.activeElement !== surface;
        surface.focus();
        if (outside && surface.savedRange && surface.contains(surface.savedRange.startContainer)) {
            const selection = window.getSelection();
            selection?.removeAllRanges();
            selection?.addRange(surface.savedRange);
        }
        const math = button.getAttribute('data-editor-math');
        const name = button.getAttribute('data-editor-command');
        if (math) {
            this.insertNode(surface, this.chip(math));
        } else if (name === 'bold' || name === 'italic' || name === 'underline') {
            document.execCommand(name);
            this.clean(surface);
        } else if (name === 'bullet' || name === 'ordered') {
            const list = document.createElement(name === 'ordered' ? 'ol' : 'ul');
            const item = document.createElement('li');
            item.appendChild(document.createElement('br'));
            list.appendChild(item);
            this.insertNode(surface, list);
        } else if (name === 'undo' || name === 'redo') {
            this.travel(field, name === 'undo' ? -1 : 1);
            return;
        } else {
            return;
        }
        this.afterEdit(field);
    }

    afterEdit(field) {
        this.writeFallback(field);
        this.remember(field);
    }

    writeFallback(field) {
        const fallback = field.querySelector('textarea');
        const surface = field.querySelector('[data-editor-surface]');
        if (!fallback || !surface) {
            return;
        }
        this.syncing = true;
        fallback.value = surface.innerText.replace(/\u00a0/g, ' ').trim();
        this.syncing = false;
    }

    syncAll() {
        this.element.querySelectorAll('[data-editor-field]').forEach((field) => {
            const surface = field.querySelector('[data-editor-surface]');
            const hidden = field.querySelector('[data-editor-document]');
            if (!surface || !hidden) {
                return;
            }
            const documentValue = this.serialize(surface);
            hidden.value = documentValue.blocks.length ? JSON.stringify(documentValue) : '';
            this.writeFallback(field);
        });
    }

    previewFile(input, frame) {
        if (!frame) {
            return;
        }
        frame.replaceChildren();
        const box = input.closest('.admin-question-image-box');
        const name = box?.querySelector('[data-image-name]');
        const file = input.files && input.files[0];
        if (name) {
            name.textContent = file ? file.name : (name.getAttribute('data-empty-name') || 'Dosya seçilmedi');
        }
        if (box) {
            box.dataset.imageState = file ? 'selected' : (name?.getAttribute('data-empty-name') === 'Kayıtlı görsel duruyor' ? 'kept' : 'empty');
        }
        if (!file) {
            const note = document.createElement('p');
            note.textContent = '250 × 250 önizleme. Görsel oranını korur.';
            frame.appendChild(note);
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            if (!input.files || input.files[0] !== file || typeof reader.result !== 'string' || !reader.result.startsWith('data:image/')) {
                return;
            }
            const image = document.createElement('img');
            image.alt = '';
            image.src = reader.result;
            frame.replaceChildren(image);
        });
        reader.readAsDataURL(file);
    }

    renderPlain(surface, text) {
        surface.replaceChildren();
        const line = document.createElement('div');
        if (text) {
            line.textContent = text;
        } else {
            line.appendChild(document.createElement('br'));
        }
        surface.appendChild(line);
    }

    render(surface, doc) {
        surface.replaceChildren();
        const blocks = Array.isArray(doc?.blocks) ? doc.blocks : [];
        if (!blocks.length) {
            this.renderPlain(surface, '');
            return;
        }
        blocks.forEach((block) => {
            if (block?.type === 'paragraph') {
                const line = document.createElement('div');
                const inlines = Array.isArray(block.inlines)
                    ? block.inlines
                    : (typeof block.text === 'string' ? [{ type: 'text', text: block.text }] : []);
                inlines.forEach((inline) => {
                    if (inline?.type === 'math' && typeof inline.latex === 'string') {
                        line.appendChild(this.chip(inline.latex));
                    } else if (typeof inline?.text === 'string') {
                        line.appendChild(this.marked(inline.text, inline.marks));
                    }
                });
                if (!line.childNodes.length) {
                    line.appendChild(document.createElement('br'));
                }
                surface.appendChild(line);
            } else if (block?.type === 'math' && typeof block.latex === 'string') {
                const line = document.createElement('div');
                line.appendChild(this.chip(block.latex));
                surface.appendChild(line);
            } else if (block?.type === 'list' && Array.isArray(block.items)) {
                const list = document.createElement(block.style === 'ordered' ? 'ol' : 'ul');
                block.items.forEach((item) => {
                    if (typeof item !== 'string') {
                        return;
                    }
                    const entry = document.createElement('li');
                    entry.textContent = item;
                    list.appendChild(entry);
                });
                surface.appendChild(list);
            }
        });
        if (!surface.childNodes.length) {
            this.renderPlain(surface, '');
        }
    }

    marked(text, marks) {
        const order = ['bold', 'italic', 'underline'].filter((mark) => Array.isArray(marks) && marks.includes(mark));
        let node = document.createElement('span');
        node.textContent = text;
        order.forEach((mark) => {
            const wrap = document.createElement(mark === 'bold' ? 'strong' : (mark === 'italic' ? 'em' : 'u'));
            wrap.appendChild(node);
            node = wrap;
        });
        return node;
    }

    chip(latex) {
        const span = document.createElement('span');
        span.className = 'q-chip';
        span.contentEditable = 'false';
        span.dataset.math = latex;
        span.textContent = this.preview(latex);
        return span;
    }

    preview(latex) {
        return latex
            .replace(/\\frac\{([^{}]*)\}\{([^{}]*)\}/g, '$1/$2')
            .replace(/\\sqrt\{([^{}]*)\}/g, '√($1)')
            .replace(/\\overline\{([^{}]*)\}/g, '$1')
            .replace(/\\left\(/g, '(')
            .replace(/\\right\)/g, ')')
            .replace(/\\(sum|int|pi|angle|triangle|parallel|perp|rightarrow|to|leftrightarrow|le|ge|neq|times|cdot|pm|degree|circ|alpha|beta|gamma|theta|Delta|infty)/g, (match) => ({
                '\\sum': '∑', '\\int': '∫', '\\pi': 'π', '\\angle': '∠', '\\triangle': '△', '\\parallel': '∥', '\\perp': '⊥',
                '\\rightarrow': '→', '\\to': '→', '\\leftrightarrow': '⇌', '\\le': '≤', '\\ge': '≥', '\\neq': '≠',
                '\\times': '×', '\\cdot': '·', '\\pm': '±', '\\degree': '°', '\\circ': '°', '\\alpha': 'α', '\\beta': 'β',
                '\\gamma': 'γ', '\\theta': 'θ', '\\Delta': 'Δ', '\\infty': '∞',
            }[match] || match))
            .replace(/[{}]/g, '')
            .replace(/\^/g, '')
            .replace(/_/g, '');
    }

    insertText(surface, text) {
        const node = document.createTextNode(text);
        this.insertNode(surface, node);
    }

    keepRange(surface) {
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || !surface.contains(selection.anchorNode)) {
            return;
        }
        surface.savedRange = selection.getRangeAt(0).cloneRange();
    }

    wireTabs(field) {
        const root = field.querySelector('[data-editor-science]');
        if (!root || root.getAttribute('data-tabs-bound') === '1') {
            return;
        }
        root.setAttribute('data-tabs-bound', '1');
        const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
        const panels = Array.from(root.querySelectorAll('[data-editor-panel]'));
        const uid = Math.random().toString(36).slice(2, 10);
        tabs.forEach((tab, index) => {
            const key = tab.getAttribute('data-editor-tab');
            const panel = panels.find((item) => item.getAttribute('data-editor-panel') === key);
            const tabId = `q-tab-${uid}-${key}`;
            const panelId = `q-panel-${uid}-${key}`;
            tab.id = tabId;
            tab.setAttribute('aria-controls', panelId);
            if (panel) {
                panel.id = panelId;
                panel.setAttribute('aria-labelledby', tabId);
            }
            tab.addEventListener('keydown', (event) => {
                let next = index;
                if (event.key === 'ArrowRight') {
                    next = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft') {
                    next = (index + tabs.length - 1) % tabs.length;
                } else if (event.key === 'Home') {
                    next = 0;
                } else if (event.key === 'End') {
                    next = tabs.length - 1;
                } else {
                    return;
                }
                event.preventDefault();
                this.selectTab(tabs, panels, tabs[next], true);
            });
            tab.addEventListener('click', () => this.selectTab(tabs, panels, tab, false));
        });
    }

    selectTab(tabs, panels, tab, focusTab) {
        const key = tab.getAttribute('data-editor-tab');
        tabs.forEach((item) => {
            const selected = item === tab;
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
            item.tabIndex = selected ? 0 : -1;
        });
        panels.forEach((panel) => {
            panel.hidden = panel.getAttribute('data-editor-panel') !== key;
        });
        if (focusTab) {
            tab.focus();
        }
    }

    insertNode(surface, node) {
        const selection = window.getSelection();
        if (selection && (!selection.rangeCount || !surface.contains(selection.anchorNode)) && surface.savedRange && surface.contains(surface.savedRange.startContainer)) {
            selection.removeAllRanges();
            selection.addRange(surface.savedRange);
        }
        if (!selection || !selection.rangeCount || !surface.contains(selection.anchorNode)) {
            surface.appendChild(node);
            return;
        }
        const range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    clean(root) {
        const nodes = Array.from(root.querySelectorAll('*'));
        nodes.forEach((node) => {
            if (!root.contains(node)) {
                return;
            }
            if (node.nodeName === 'SCRIPT' || node.nodeName === 'IFRAME' || node.nodeName === 'STYLE' || node.nodeName === 'SVG' || node.nodeName === 'IMG' || node.nodeName === 'A') {
                node.remove();
                return;
            }
            if (!ALLOWED.has(node.nodeName)) {
                this.unwrap(node);
                return;
            }
            if (node.nodeName === 'SPAN' && !node.dataset.math) {
                this.unwrap(node);
            }
            Array.from(node.attributes).forEach((attribute) => {
                if (attribute.name !== 'data-math' && attribute.name !== 'class' && attribute.name !== 'contenteditable') {
                    node.removeAttribute(attribute.name);
                }
            });
        });
    }

    unwrap(node) {
        const parent = node.parentNode;
        if (!parent) {
            return;
        }
        while (node.firstChild) {
            parent.insertBefore(node.firstChild, node);
        }
        parent.removeChild(node);
    }

    serialize(surface) {
        const blocks = [];
        const flush = (inlines) => {
            const clean = inlines.filter((inline) => inline.type === 'math' || (inline.text && inline.text.trim() !== ''));
            if (clean.length) {
                blocks.push({ type: 'paragraph', inlines: clean });
            }
        };
        const walk = (node, inlines, marks) => {
            if (node.nodeType === Node.TEXT_NODE) {
                if (node.textContent) {
                    const inline = { type: 'text', text: node.textContent };
                    if (marks.length) {
                        inline.marks = marks;
                    }
                    inlines.push(inline);
                }
                return;
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return;
            }
            if (node.dataset && node.dataset.math) {
                inlines.push({ type: 'math', latex: node.dataset.math });
                return;
            }
            if (node.nodeName === 'UL' || node.nodeName === 'OL') {
                flush(inlines.splice(0, inlines.length));
                const items = Array.from(node.children)
                    .filter((child) => child.nodeName === 'LI')
                    .map((child) => child.textContent.replace(/\s+/g, ' ').trim())
                    .filter((item) => item !== '');
                if (items.length) {
                    blocks.push({ type: 'list', style: node.nodeName === 'OL' ? 'ordered' : 'bullet', items });
                }
                return;
            }
            if (node.nodeName === 'BR') {
                return;
            }
            const next = marks.slice();
            if (node.nodeName === 'STRONG' || node.nodeName === 'B') {
                next.push('bold');
            } else if (node.nodeName === 'EM' || node.nodeName === 'I') {
                next.push('italic');
            } else if (node.nodeName === 'U') {
                next.push('underline');
            }
            Array.from(node.childNodes).forEach((child) => walk(child, inlines, next));
        };
        Array.from(surface.childNodes).forEach((child) => {
            if (child.nodeName === 'UL' || child.nodeName === 'OL') {
                walk(child, [], []);
                return;
            }
            const inlines = [];
            walk(child, inlines, []);
            flush(inlines);
        });
        return { schemaVersion: 1, blocks };
    }

    remember(field) {
        const surface = field.querySelector('[data-editor-surface]');
        if (!surface) {
            return;
        }
        const json = JSON.stringify(this.serialize(surface));
        const state = this.history.get(field) || { items: [], index: -1 };
        if (state.items[state.index] === json) {
            return;
        }
        state.items = state.items.slice(0, state.index + 1);
        state.items.push(json);
        if (state.items.length > 40) {
            state.items.shift();
        }
        state.index = state.items.length - 1;
        this.history.set(field, state);
    }

    travel(field, step) {
        const state = this.history.get(field);
        const surface = field.querySelector('[data-editor-surface]');
        if (!state || !surface) {
            return;
        }
        const next = state.index + step;
        if (next < 0 || next >= state.items.length) {
            return;
        }
        state.index = next;
        this.render(surface, JSON.parse(state.items[next]));
        this.writeFallback(field);
    }
}
