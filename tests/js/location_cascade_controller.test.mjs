import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import LocationCascadeController from '../../assets/controllers/location_cascade_controller.js';

const queue = [];

afterEach(() => {
    queue.length = 0;
    delete globalThis.fetch;
    delete globalThis.document;
    delete globalThis.window;
});

test('a late province response does not replace the newer district list or hidden values', async () => {
    installDom();
    const controller = createController();
    controller.provinceTarget.value = 'province-a';
    controller.provinceChanged();
    const provinceA = queue.shift();
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');
    assert.equal(controller.provinceIdTarget.value, 'province-a');

    controller.provinceTarget.value = 'province-b';
    controller.provinceChanged();
    const provinceB = queue.shift();
    assert.equal(parentOf(provinceA.url), 'province-a');
    assert.equal(parentOf(provinceB.url), 'province-b');
    assert.equal(controller.provinceIdTarget.value, 'province-b');
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');

    provinceB.resolve(locations([{ id: 'district-b', name: 'İlçe B' }]));
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin', 'İlçe B']);
    assert.equal(controller.districtTarget.value, '');
    assert.equal(controller.districtIdTarget.value, controller.districtTarget.value);
    assert.equal(controller.schoolIdTarget.value, controller.schoolTarget.value);

    provinceA.resolve(locations([{ id: 'district-a', name: 'İlçe A' }]));
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin', 'İlçe B']);
    assert.deepEqual(values(controller.districtTarget), ['', 'district-b']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');
    assert.equal(controller.provinceIdTarget.value, 'province-b');
});

test('a late district response does not replace the newer school list or hidden values', async () => {
    installDom();
    const controller = createController();
    controller.districtTarget.value = 'district-a';
    controller.districtChanged();
    const districtA = queue.shift();
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);
    assert.equal(controller.schoolIdTarget.value, '');
    assert.equal(controller.districtIdTarget.value, 'district-a');

    controller.districtTarget.value = 'district-b';
    controller.districtChanged();
    const districtB = queue.shift();
    assert.equal(parentOf(districtA.url), 'district-a');
    assert.equal(parentOf(districtB.url), 'district-b');
    assert.equal(controller.districtIdTarget.value, 'district-b');
    assert.equal(controller.schoolIdTarget.value, '');
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);

    districtB.resolve(locations([{ id: 'school-b', name: 'Okul B' }]));
    await flush();
    assert.deepEqual(names(controller.schoolTarget), ['Seçin', 'Okul B']);
    assert.equal(controller.schoolTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, controller.schoolTarget.value);

    districtA.resolve(locations([{ id: 'school-a', name: 'Okul A' }]));
    await flush();
    assert.deepEqual(names(controller.schoolTarget), ['Seçin', 'Okul B']);
    assert.deepEqual(values(controller.schoolTarget), ['', 'school-b']);
    assert.equal(controller.schoolIdTarget.value, '');
    assert.equal(controller.districtIdTarget.value, 'district-b');
});

test('changing province invalidates a pending school request from the previous district', async () => {
    installDom();
    const controller = createController();
    controller.districtTarget.value = 'district-a';
    controller.districtChanged();
    const schools = queue.shift();

    controller.provinceTarget.value = 'province-b';
    controller.provinceChanged();
    const districts = queue.shift();
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.equal(controller.schoolIdTarget.value, '');
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.provinceIdTarget.value, 'province-b');

    schools.resolve(locations([{ id: 'school-a', name: 'Okul A' }]));
    await flush();
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);
    assert.equal(controller.schoolIdTarget.value, '');

    districts.resolve(locations([{ id: 'district-b', name: 'İlçe B' }]));
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin', 'İlçe B']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');
});

test('saved selection loading ignores a district response after the province changes', async () => {
    installDom();
    const controller = createController({
        province: 'province-a',
        district: 'district-a',
        school: 'school-a',
    });
    const loading = controller.connect();
    const provinces = queue.shift();
    provinces.resolve(locations([
        { id: 'province-a', name: 'İl A' },
        { id: 'province-b', name: 'İl B' },
    ]));
    await flush();
    const savedDistricts = queue.shift();
    assert.equal(parentOf(savedDistricts.url), 'province-a');
    assert.equal(controller.provinceTarget.value, 'province-a');
    assert.equal(controller.provinceIdTarget.value, 'province-a');

    controller.provinceTarget.value = 'province-b';
    controller.provinceChanged();
    const nextDistricts = queue.shift();
    assert.equal(controller.provinceIdTarget.value, 'province-b');
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.deepEqual(names(controller.schoolTarget), ['Seçin']);

    savedDistricts.resolve(locations([{ id: 'district-a', name: 'İlçe A' }]));
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');

    nextDistricts.resolve(locations([{ id: 'district-b', name: 'İlçe B' }]));
    await flush();
    await loading;
    assert.deepEqual(names(controller.districtTarget), ['Seçin', 'İlçe B']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.provinceIdTarget.value, 'province-b');
    assert.equal(controller.schoolIdTarget.value, controller.schoolTarget.value);
});

test('network and invalid JSON failures leave the cleared selection in place', async () => {
    installDom();
    const controller = createController();
    controller.provinceTarget.value = 'province-a';
    controller.provinceChanged();
    queue.shift().reject(new Error('network'));
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.schoolIdTarget.value, '');

    controller.provinceTarget.value = 'province-b';
    controller.provinceChanged();
    queue.shift().resolve({
        ok: true,
        json: async () => {
            throw new Error('invalid json');
        },
    });
    await flush();
    assert.deepEqual(names(controller.districtTarget), ['Seçin']);
    assert.equal(controller.districtTarget.value, '');
    assert.equal(controller.districtIdTarget.value, '');
    assert.equal(controller.provinceIdTarget.value, 'province-b');
});

function installDom() {
    queue.length = 0;
    globalThis.window = { location: { origin: 'http://localhost' } };
    globalThis.document = {
        createElement(tag) {
            if ('option' !== tag) {
                throw new Error(tag);
            }

            return { value: '', textContent: '', selected: false };
        },
    };
    globalThis.fetch = (url) => new Promise((resolve, reject) => {
        queue.push({ url: String(url), resolve, reject });
    });
}

function createController(values = {}) {
    const controller = Object.create(LocationCascadeController.prototype);
    controller.urlValue = 'http://localhost/ogretmen/profil/konumlar';
    controller.provinceValue = values.province ?? '';
    controller.districtValue = values.district ?? '';
    controller.schoolValue = values.school ?? '';
    controller.provinceTarget = createSelect();
    controller.districtTarget = createSelect();
    controller.schoolTarget = createSelect();
    controller.provinceIdTarget = { value: values.province ?? '' };
    controller.districtIdTarget = { value: values.district ?? '' };
    controller.schoolIdTarget = { value: values.school ?? '' };

    return controller;
}

function createSelect() {
    return {
        value: '',
        options: [],
        replaceChildren(...children) {
            this.options = [...children];
            this.value = this.options[0]?.value ?? '';
        },
        appendChild(option) {
            this.options.push(option);
            if (option.selected) {
                this.value = option.value;
            }
        },
    };
}

function locations(rows) {
    return {
        ok: true,
        json: async () => ({ locations: rows }),
    };
}

function names(select) {
    return select.options.map((option) => option.textContent);
}

function values(select) {
    return select.options.map((option) => option.value);
}

function parentOf(url) {
    return new URL(url).searchParams.get('parent');
}

async function flush() {
    for (let step = 0; step < 6; step += 1) {
        await new Promise((resolve) => {
            setImmediate(resolve);
        });
    }
}
