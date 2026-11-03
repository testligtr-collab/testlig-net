import { Controller } from '@hotwired/stimulus';
import { counterText, formatDue } from '../homework_text.js';

/**
 * Live counters and "Taslak özeti" for the teacher draft homework form.
 * Counts Unicode code points (see homework_text.js) so emoji match the server's mb_strlen.
 */
export default class extends Controller {
    static targets = [
        'title',
        'description',
        'titleCounter',
        'descriptionCounter',
        'classroom',
        'dueDate',
        'dueTime',
        'summaryTitle',
        'summaryClassroom',
        'summaryDue',
    ];

    static values = {
        titleMax: Number,
        descriptionMax: Number,
    };

    connect() {
        this.refresh();
        const alert = this.element.parentElement?.querySelector('.hw-alert');
        if (alert instanceof HTMLElement) {
            alert.focus({ preventScroll: false });
        }
    }

    refresh() {
        if (this.hasTitleTarget && this.hasTitleCounterTarget) {
            this.renderCounter(this.titleTarget, this.titleCounterTarget, this.titleMaxValue);
        }
        if (this.hasDescriptionTarget && this.hasDescriptionCounterTarget) {
            this.renderCounter(this.descriptionTarget, this.descriptionCounterTarget, this.descriptionMaxValue);
        }
        if (this.hasSummaryTitleTarget && this.hasTitleTarget) {
            const title = this.titleTarget.value.trim();
            this.summaryTitleTarget.textContent = title === '' ? '—' : title;
        }
        if (this.hasSummaryClassroomTarget && this.hasClassroomTarget) {
            const option = this.classroomTarget.selectedOptions[0];
            const label = option && option.value !== '' ? option.textContent.trim() : '';
            this.summaryClassroomTarget.textContent = label === '' ? '—' : label;
        }
        if (this.hasSummaryDueTarget && this.hasDueDateTarget && this.hasDueTimeTarget) {
            this.summaryDueTarget.textContent = formatDue(this.dueDateTarget.value, this.dueTimeTarget.value);
        }
    }

    renderCounter(field, counter, max) {
        const text = counterText(field.value, max);
        counter.textContent = text;
        const over = Number.parseInt(text, 10) > max;
        counter.classList.toggle('hw-counter--over', over);
    }
}
