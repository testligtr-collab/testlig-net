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
        this.fill(this.provinceTarget, null, this.provinceValue).then(() => {
            if ('' === this.provinceValue) {
                this.clear(this.districtTarget);
                this.clear(this.schoolTarget);

                return;
            }

            return this.fill(this.districtTarget, this.provinceValue, this.districtValue).then(() => {
                if ('' === this.districtValue) {
                    this.clear(this.schoolTarget);

                    return;
                }

                return this.fill(this.schoolTarget, this.districtValue, this.schoolValue);
            });
        });
    }

    provinceChanged() {
        const provinceId = this.provinceTarget.value;
        this.provinceIdTarget.value = provinceId;
        this.districtIdTarget.value = '';
        this.schoolIdTarget.value = '';
        this.clear(this.schoolTarget);
        if ('' === provinceId) {
            this.clear(this.districtTarget);

            return;
        }
        this.fill(this.districtTarget, provinceId, '');
    }

    districtChanged() {
        const districtId = this.districtTarget.value;
        this.districtIdTarget.value = districtId;
        this.schoolIdTarget.value = '';
        if ('' === districtId) {
            this.clear(this.schoolTarget);

            return;
        }
        this.fill(this.schoolTarget, districtId, '');
    }

    schoolChanged() {
        this.schoolIdTarget.value = this.schoolTarget.value;
    }

    async fill(select, parentId, selectedId) {
        const url = new URL(this.urlValue, window.location.origin);
        if (parentId) {
            url.searchParams.set('parent', parentId);
        }
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        this.clear(select);
        if (!response.ok) {
            return;
        }
        const payload = await response.json();
        const rows = Array.isArray(payload.locations) ? payload.locations : [];
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
        if (select === this.provinceTarget) {
            this.provinceIdTarget.value = select.value;
        } else if (select === this.districtTarget) {
            this.districtIdTarget.value = select.value;
        } else {
            this.schoolIdTarget.value = select.value;
        }
    }

    clear(select) {
        select.replaceChildren();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'Seçin';
        select.appendChild(empty);
    }
}
