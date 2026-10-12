import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['province', 'district', 'school', 'provinceId', 'districtId', 'schoolId'];

    static values = {
        url: String,
        province: String,
        district: String,
        school: String,
    };

    connect() {
        return this.loadSavedSelection();
    }

    disconnect() {
        this.invalidate('province');
        this.invalidate('district');
        this.invalidate('school');
    }

    provinceChanged() {
        const provinceId = this.provinceTarget.value;
        this.provinceIdTarget.value = provinceId;
        this.clear(this.districtTarget);
        this.districtIdTarget.value = '';
        this.clear(this.schoolTarget);
        this.schoolIdTarget.value = '';
        this.invalidate('province');
        this.invalidate('school');
        if ('' === provinceId) {
            this.invalidate('district');

            return;
        }
        this.fill(this.districtTarget, provinceId, '', this.issue('district'));
    }

    districtChanged() {
        const districtId = this.districtTarget.value;
        this.districtIdTarget.value = districtId;
        this.clear(this.schoolTarget);
        this.schoolIdTarget.value = '';
        this.invalidate('district');
        if ('' === districtId) {
            this.invalidate('school');

            return;
        }
        this.fill(this.schoolTarget, districtId, '', this.issue('school'));
    }

    schoolChanged() {
        this.schoolIdTarget.value = this.schoolTarget.value;
    }

    async loadSavedSelection() {
        const provinceTicket = this.issue('province');
        await this.fill(this.provinceTarget, null, this.provinceValue, provinceTicket);
        if (!this.isCurrent(provinceTicket)) {
            return;
        }
        const provinceId = this.provinceTarget.value;
        if ('' === provinceId) {
            this.clear(this.districtTarget);
            this.districtIdTarget.value = '';
            this.clear(this.schoolTarget);
            this.schoolIdTarget.value = '';

            return;
        }

        const districtTicket = this.issue('district');
        await this.fill(this.districtTarget, provinceId, this.districtValue, districtTicket);
        if (!this.isCurrent(districtTicket)) {
            return;
        }
        const districtId = this.districtTarget.value;
        if ('' === districtId) {
            this.clear(this.schoolTarget);
            this.schoolIdTarget.value = '';

            return;
        }

        const schoolTicket = this.issue('school');
        await this.fill(this.schoolTarget, districtId, this.schoolValue, schoolTicket);
    }

    async fill(select, parentId, selectedId, ticket) {
        const url = new URL(this.urlValue, window.location.origin);
        if (parentId) {
            url.searchParams.set('parent', parentId);
        }
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: ticket.signal,
            });
            if (!this.isCurrent(ticket)) {
                return;
            }
            if (!response.ok) {
                this.clear(select);
                this.writeHidden(select);

                return;
            }
            const payload = await response.json();
            if (!this.isCurrent(ticket)) {
                return;
            }
            this.clear(select);
            const rows = payload && Array.isArray(payload.locations) ? payload.locations : [];
            rows.forEach((row) => {
                if (!row || 'string' !== typeof row.id || 'string' !== typeof row.name) {
                    return;
                }
                const option = document.createElement('option');
                option.value = row.id;
                option.textContent = row.name;
                if (row.id === selectedId) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
            this.writeHidden(select);
        } catch {
            if (!this.isCurrent(ticket)) {
                return;
            }
            this.clear(select);
            this.writeHidden(select);
        }
    }

    issue(level) {
        this.ensureRequests();
        this.requests[level] += 1;
        this.abort[level]?.abort();
        const controller = new AbortController();
        this.abort[level] = controller;

        return { level, id: this.requests[level], signal: controller.signal };
    }

    invalidate(level) {
        this.ensureRequests();
        this.requests[level] += 1;
        this.abort[level]?.abort();
        this.abort[level] = null;
    }

    isCurrent(ticket) {
        return ticket.id === this.requests[ticket.level];
    }

    ensureRequests() {
        if (!this.requests) {
            this.requests = { province: 0, district: 0, school: 0 };
            this.abort = { province: null, district: null, school: null };
        }
    }

    clear(select) {
        select.replaceChildren();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'Seçin';
        select.appendChild(empty);
    }

    writeHidden(select) {
        if (select === this.provinceTarget) {
            this.provinceIdTarget.value = select.value;
        } else if (select === this.districtTarget) {
            this.districtIdTarget.value = select.value;
        } else {
            this.schoolIdTarget.value = select.value;
        }
    }
}
