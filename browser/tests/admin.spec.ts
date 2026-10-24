import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

const NEWS_VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
] as const;

test('admin and superadmin menus stay inside their roles', async ({ browser }, info) => {
  const data = manifest();
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  installVideoAbort(adminPage);
  await watch(adminPage);
  await login(adminPage, data.users.admin);
  await expect(adminPage).toHaveURL(/\/yonetim$/);
  await adminPage.emulateMedia({ reducedMotion: 'reduce' });
  const dashboard = await adminPage.goto('/yonetim');
  expect(dashboard?.headers()['cache-control'] ?? '').toContain('no-store');
  expect(dashboard?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(adminPage.locator('.panel-role')).toHaveText('Yönetici');
  await expandAdminGroups(adminPage);
  for (const label of ['Genel Bakış', 'Sistem durumu', 'Kullanıcılar', 'Kurumlar', 'Müfredat', 'Öğrenme içerikleri', 'Soru havuzu', 'Test ve sınavlar', 'Haberler']) {
    await expect(adminPage.getByRole('link', { name: label, exact: true }).first()).toBeVisible();
  }
  for (const label of ['Şube ve kampüsler', 'Simülasyonlar', 'Paketler ve teklifler', 'SMS']) {
    await expect(adminPage.getByRole('link', { name: label, exact: true })).toHaveCount(0);
  }
  for (const label of ['Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim']) {
    await expect(adminPage.getByRole('link', { name: label })).toHaveCount(0);
  }
  await expect(adminPage.locator('a.panel-nav-link[aria-current="page"]')).toHaveText('Genel Bakış');
  for (const viewport of [...VIEWPORTS, { name: '390', width: 390, height: 844 }, { name: '1440', width: 1440, height: 900 }, { name: '1920', width: 1920, height: 1080 }]) {
    await adminPage.setViewportSize(viewport);
    await adminPage.goto('/yonetim');
    await assertLayout(adminPage);
    await assertOverviewChrome(adminPage, viewport.width);
    const primary = adminPage.getByRole('link', { name: 'Yeni içerik' });
    if (await primary.count()) {
      const primaryBox = await primary.first().boundingBox();
      expect(primaryBox).not.toBeNull();
      expect(primaryBox!.height).toBeGreaterThanOrEqual(44);
    }
    if (viewport.width >= 1024) {
      await expect(adminPage.locator('#panel-sidebar')).toBeVisible();
    }
    await shot(adminPage, info, 'admin', 'dashboard', viewport.name);
    if (viewport.width < 1024) {
      await closeDrawer(adminPage);
    }
  }
  await adminPage.setViewportSize({ width: 1440, height: 900 });
  await adminPage.goto('/yonetim');
  await openOverviewCards(adminPage);
  await expect(adminPage.locator('a[href^="/yonetim/bolum/"]')).toHaveCount(0);
  await expect(adminPage.locator('a[href="/yonetim/odemeler"]')).toHaveCount(0);
  await expect(adminPage.locator('a[href="/yonetim/denetim"]')).toHaveCount(0);
  await expect(adminPage.locator('a[href="/yonetim/rol-yetkileri"]')).toHaveCount(0);
  await expect(adminPage.locator('a.admin-mini-link[href="/yonetim/kullanicilar"]')).toContainText('Çalışıyor');
  await adminPage.setViewportSize({ width: 640, height: 800 });
  await adminPage.goto('/yonetim');
  const zoomMenu = adminPage.getByRole('button', { name: 'Menü', exact: true });
  await expect(zoomMenu).toBeVisible();
  const zoomBox = await zoomMenu.boundingBox();
  expect(zoomBox).not.toBeNull();
  expect(zoomBox!.width).toBeGreaterThanOrEqual(44);
  expect(zoomBox!.height).toBeGreaterThanOrEqual(44);
  await expect(adminPage.getByRole('heading', { level: 1, name: 'Genel Bakış' })).toBeVisible();
  await expect(adminPage.getByRole('heading', { level: 1 })).toHaveCount(1);
  await adminPage.setViewportSize({ width: 1280, height: 900 });
  for (const [path, name] of [
    ['/yonetim/kullanicilar', 'kullanicilar'],
    ['/yonetim/kurumlar', 'kurumlar'],
    ['/yonetim/mufredat', 'mufredat'],
    ['/yonetim/icerikler', 'icerikler'],
    ['/yonetim/sorular', 'sorular'],
    ['/yonetim/testler', 'testler'],
    ['/yonetim/sistem', 'sistem'],
  ] as const) {
    await adminPage.goto(path);
    await assertLayout(adminPage);
    await shot(adminPage, info, 'admin', name, '1280');
  }
  await adminPage.goto('/yonetim/kullanicilar');
  await adminPage.locator('#user-filter-role').selectOption('ROLE_STUDENT');
  await adminPage.getByRole('button', { name: 'Filtrele' }).click();
  await expect(adminPage).toHaveURL(/role=ROLE_STUDENT/);
  await expect(adminPage.locator('#user-filter-role')).toHaveValue('ROLE_STUDENT');
  await adminPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
  await expect(adminPage).toHaveURL(/\/yonetim\/kullanicilar$/);
  for (const viewport of [
    { name: '390', width: 390, height: 844 },
    { name: '768', width: 768, height: 1024 },
    { name: '1440', width: 1440, height: 900 },
    { name: '1920', width: 1920, height: 1080 },
  ]) {
    await adminPage.setViewportSize(viewport);
    await adminPage.goto('/yonetim/kullanicilar');
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Kullanıcılar' })).toBeVisible();
    await expect(adminPage.locator('[data-user-summary]')).toHaveCount(4);
    await expect(adminPage.getByRole('button', { name: 'Filtrele' })).toBeVisible();
    const metrics = await adminPage.evaluate(() => ({
      scroll: document.documentElement.scrollWidth,
      client: document.documentElement.clientWidth,
    }));
    expect(metrics.scroll, `${viewport.name} scroll ${metrics.scroll} client ${metrics.client}`).toBeLessThanOrEqual(metrics.client + 1);
    const alignment = await adminPage.evaluate(() => {
      const row = document.querySelector('.admin-user-table tbody tr');
      if (!(row instanceof HTMLTableRowElement)) {
        return { rows: 0, cells: [], dateDisplay: '', personDisplay: '', dateBorder: '', rowLine: '' };
      }
      const cells = [...row.querySelectorAll(':scope > td')].map((cell) => {
        const box = cell.getBoundingClientRect();
        const style = getComputedStyle(cell);
        return {
          display: style.display,
          background: style.backgroundColor,
          top: box.top,
          bottom: box.bottom,
        };
      });
      const stack = row.querySelector('.admin-user-date__stack');
      const person = row.querySelector('.admin-user-person__layout');
      const date = row.querySelector('td.admin-user-date');
      return {
        rows: 1,
        cells,
        dateDisplay: stack instanceof HTMLElement ? getComputedStyle(stack).display : '',
        personDisplay: person instanceof HTMLElement ? getComputedStyle(person).display : '',
        dateBorder: date instanceof HTMLElement ? getComputedStyle(date).borderBottomColor : '',
        rowLine: getComputedStyle(row).backgroundImage,
      };
    });
    expect(alignment.rows, viewport.name).toBe(1);
    if (viewport.width >= 1024) {
      const first = alignment.cells[0];
      for (const cell of alignment.cells) {
        expect(cell.display, viewport.name).toBe('table-cell');
        expect(cell.background, viewport.name).toBe('rgba(0, 0, 0, 0)');
        expect(Math.abs(cell.top - first.top), viewport.name).toBeLessThanOrEqual(1);
        expect(Math.abs(cell.bottom - first.bottom), viewport.name).toBeLessThanOrEqual(1);
      }
      expect(alignment.dateDisplay, viewport.name).toBe('grid');
      expect(alignment.personDisplay, viewport.name).toBe('flex');
      expect(alignment.dateBorder, viewport.name).toBe('rgba(0, 0, 0, 0)');
      expect(alignment.rowLine, viewport.name).toContain('linear-gradient');
      await adminPage.locator('.admin-user-date').first().hover();
      const hoverState = await adminPage.evaluate(() => {
        const row = document.querySelector('.admin-user-table tbody tr');
        const date = row?.querySelector('td.admin-user-date');
        return {
          row: row instanceof Element && row.matches(':hover'),
          date: date instanceof Element && date.matches(':hover'),
        };
      });
      expect(hoverState.row, viewport.name).toBe(true);
      expect(hoverState.date, viewport.name).toBe(true);
    } else {
      expect(alignment.cells.every((cell) => cell.display === 'block'), viewport.name).toBe(true);
      await expect(adminPage.locator('a.admin-user-detail').first()).toBeVisible();
    }
    await adminPage.getByLabel('Ara').fill('user-list-browser-missing');
    await adminPage.getByRole('button', { name: 'Filtrele' }).click();
    await expect(adminPage.getByText('Filtrelere uygun kullanıcı bulunamadı.')).toBeVisible();
    await adminPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
    await expect(adminPage).toHaveURL(/\/yonetim\/kullanicilar$/);
    const filter = adminPage.getByRole('button', { name: 'Filtrele' });
    await adminPage.locator('#user-filter-q').focus();
    await adminPage.keyboard.press('Tab');
    await adminPage.keyboard.press('Tab');
    await adminPage.keyboard.press('Tab');
    await adminPage.keyboard.press('Tab');
    await expect(filter).toBeFocused();
    const outline = await filter.evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(outline).toBe('solid');
    const detail = adminPage.locator('a.admin-user-detail').first();
    if (await detail.count()) {
      await detail.click();
      await expect(adminPage).toHaveURL(/\/yonetim\/kullanicilar\/[0-9a-f-]{36}$/i);
      await adminPage.goto('/yonetim/kullanicilar');
    }
    await adminPage.mouse.move(0, 0);
    await shot(adminPage, info, 'admin', 'kullanicilar', viewport.name);
  }
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '1920', width: 1920, height: 1080 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ]) {
    await adminPage.setViewportSize({ width: viewport.width, height: viewport.height });
    const detail = await adminPage.goto(data.paths.userDetail);
    expect(detail?.status()).toBe(200);
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Kullanıcı detayı' })).toBeVisible();
    await expect(adminPage.getByRole('heading', { name: 'Rolleri güncelle' })).toBeVisible();
    await expect(adminPage.getByRole('heading', { name: 'Durumu güncelle' })).toBeVisible();
    await expect(adminPage.locator('form[name="admin_user_roles"]')).toHaveCount(1);
    await expect(adminPage.locator('form[name="admin_user_status"]')).toHaveCount(1);
    await expect(adminPage.locator('.admin-user-detail-name')).toContainText('Uzunad');
    await expect(adminPage.locator('.admin-user-detail-email')).toContainText('browser-detail-');
    await expect(adminPage.locator('.admin-user-detail-pills')).toContainText('Öğretmen');
    await expect(adminPage.locator('.admin-user-detail-pills')).toContainText('Veli');
    await expect(adminPage.locator('.admin-user-detail-pills')).toContainText('Zümre başkanı');
    const layout = await adminPage.evaluate(() => {
      const page = document.querySelector('.admin-user-detail-page');
      const forms = document.querySelector('.admin-user-detail-forms');
      const roles = document.querySelector('.admin-user-role-options');
      const cards = Array.from(document.querySelectorAll('.admin-user-detail-card')).map((node) => node.getBoundingClientRect().width);
      const view = document.documentElement.clientWidth;
      return {
        page: page?.scrollWidth ?? 0,
        doc: document.documentElement.scrollWidth,
        view,
        formColumns: forms ? getComputedStyle(forms).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
        roleColumns: roles ? getComputedStyle(roles).gridTemplateColumns.split(' ').filter(Boolean).length : 0,
        cards,
      };
    });
    expect(layout.page, viewport.name).toBeLessThanOrEqual(layout.view + 1);
    expect(layout.doc, viewport.name).toBeLessThanOrEqual(layout.view + 1);
    expect(layout.formColumns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    expect(layout.roleColumns, viewport.name).toBe(viewport.width >= 768 ? 2 : 1);
    if (viewport.width >= 1024) {
      expect(layout.cards[0], viewport.name).toBeGreaterThan(layout.cards[1]);
      const ratio = layout.cards[0] / (layout.cards[0] + layout.cards[1]);
      expect(ratio, viewport.name).toBeGreaterThan(0.55);
      expect(ratio, viewport.name).toBeLessThan(0.68);
    }
    const checkbox = adminPage.locator('form[name="admin_user_roles"] .admin-user-role-option input[type="checkbox"]').first();
    await checkbox.focus();
    await adminPage.keyboard.press('Tab');
    const nextCheckbox = adminPage.locator('form[name="admin_user_roles"] .admin-user-role-option input[type="checkbox"]').nth(1);
    await expect(nextCheckbox).toBeFocused();
    const outline = await nextCheckbox.evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(outline, viewport.name).toBe('solid');
    const shotHeight = await adminPage.evaluate(() => document.documentElement.scrollHeight);
    await adminPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(shotHeight, 2400)),
    });
    const file = path.join('artifacts', 'screenshots', `admin-kullanici-detay-${viewport.name}.png`);
    await adminPage.screenshot({ path: file, fullPage: false });
    await info.attach(`admin-kullanici-detay-${viewport.name}.png`, { path: file, contentType: 'image/png' });
  }
  await adminPage.setViewportSize({ width: 1280, height: 900 });
  await adminPage.goto(data.paths.reviewDetail);
  await expect(adminPage.getByRole('button', { name: 'Yayımla' })).toBeVisible();
  const logout = adminPage.locator('form.admin-logout-form');
  await expect(logout).toHaveAttribute('method', 'post');
  await expect(logout.locator('input[name="_csrf_token"]')).toHaveValue(/.+/);
  await assertClean(adminPage);
  await admin.close();

  const sa = await browser.newContext();
  const saPage = await sa.newPage();
  installVideoAbort(saPage);
  await watch(saPage);
  await login(saPage, data.users.superadmin);
  await saPage.goto('/yonetim');
  await expect(saPage.locator('.panel-role')).toHaveText('Süper Yönetici');
  await expandAdminGroups(saPage);
  await saPage.setViewportSize({ width: 1440, height: 900 });
  await saPage.goto(data.paths.teacherApplications);
  await saPage.locator('#teacher-app-filter-q').fill('teacher-app-missing-zzzz');
  await saPage.getByRole('button', { name: 'Filtrele' }).click();
  await expect(saPage.getByText('Filtrelere uygun başvuru bulunamadı.')).toBeVisible();
  await saPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
  await expect(saPage.getByRole('link', { name: 'İncele' }).first()).toBeVisible();
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ]) {
    await saPage.setViewportSize({ width: viewport.width, height: viewport.height });
    const listResponse = await saPage.goto(data.paths.teacherApplications);
    expect(listResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Öğretmen başvuruları' })).toBeVisible();
    await expect(saPage.getByText('Başvuru onayı öğretmen rolü vermez.')).toBeVisible();
    await expect(saPage.locator('[data-teacher-app-summary="total"]')).not.toHaveText('0');
    const listOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-teacher-app-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(listOverflow.page, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    expect(listOverflow.doc, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    const listHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(listHeight, 2400)),
    });
    const listFile = path.join('artifacts', 'screenshots', `admin-ogretmen-basvurulari-${viewport.name}.png`);
    await saPage.screenshot({ path: listFile, fullPage: false });
    await info.attach(`admin-ogretmen-basvurulari-${viewport.name}.png`, { path: listFile, contentType: 'image/png' });

    const detailResponse = await saPage.goto(data.paths.teacherApplication);
    expect(detailResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Öğretmen başvurusu' })).toBeVisible();
    await expect(saPage.getByRole('button', { name: 'Onayla' })).toBeVisible();
    await expect(saPage.getByRole('button', { name: 'Reddet' })).toBeVisible();
    const reasonLabels = await saPage.locator('#teacher-application-reason option').allTextContents();
    expect(reasonLabels).toContain('Eksik belgeler');
    expect(reasonLabels.join(' ')).not.toContain('incomplete_documents');
    await expect(saPage.getByText('küçük harfle')).toHaveCount(0);
    await expect(saPage.getByRole('link', { name: 'Listeye dön' })).toBeVisible();
    await expect(saPage.locator('.admin-teacher-app-name')).toContainText('Uzunad');
    const detailOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-teacher-app-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(detailOverflow.page, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    expect(detailOverflow.doc, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    const detailHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(detailHeight, 2400)),
    });
    const detailFile = path.join('artifacts', 'screenshots', `admin-ogretmen-basvuru-${viewport.name}.png`);
    await saPage.screenshot({ path: detailFile, fullPage: false });
    await info.attach(`admin-ogretmen-basvuru-${viewport.name}.png`, { path: detailFile, contentType: 'image/png' });
  }
  await saPage.goto(data.paths.institutionApplications);
  await saPage.locator('#institution-app-filter-q').fill('institution-app-missing-zzzz');
  await saPage.getByRole('button', { name: 'Filtrele' }).click();
  await expect(saPage.getByText('Filtrelere uygun başvuru bulunamadı.')).toBeVisible();
  await saPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
  await expect(saPage.getByRole('link', { name: 'İncele' }).first()).toBeVisible();
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ]) {
    await saPage.setViewportSize({ width: viewport.width, height: viewport.height });
    const listResponse = await saPage.goto(data.paths.institutionApplications);
    expect(listResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Kurum başvuruları' })).toBeVisible();
    await expect(saPage.getByText('Başvuru onayı kurum, üyelik veya rol vermez.')).toBeVisible();
    await expect(saPage.locator('[data-institution-app-summary="total"]')).not.toHaveText('0');
    await expect(saPage.getByText('UzunKurum', { exact: false }).first()).toBeVisible();
    const listOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-institution-app-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(listOverflow.page, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    expect(listOverflow.doc, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    const listHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(listHeight, 2400)),
    });
    const listFile = path.join('artifacts', 'screenshots', `admin-kurum-basvurulari-${viewport.name}.png`);
    await saPage.screenshot({ path: listFile, fullPage: false });
    await info.attach(`admin-kurum-basvurulari-${viewport.name}.png`, { path: listFile, contentType: 'image/png' });

    const detailResponse = await saPage.goto(data.paths.institutionApplication);
    expect(detailResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Kurum başvurusu' })).toBeVisible();
    await expect(saPage.getByRole('button', { name: 'Onayla' })).toBeVisible();
    await expect(saPage.getByRole('button', { name: 'Reddet' })).toBeVisible();
    const reasonLabels = await saPage.locator('#institution-application-reason option').allTextContents();
    expect(reasonLabels).toContain('Eksik belgeler');
    expect(reasonLabels.join(' ')).not.toContain('incomplete_documents');
    await expect(saPage.locator('.admin-institution-app-name')).toContainText('UzunKurum');
    const detailOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-institution-app-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(detailOverflow.page, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    expect(detailOverflow.doc, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    const detailHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(detailHeight, 2400)),
    });
    const detailFile = path.join('artifacts', 'screenshots', `admin-kurum-basvuru-${viewport.name}.png`);
    await saPage.screenshot({ path: detailFile, fullPage: false });
    await info.attach(`admin-kurum-basvuru-${viewport.name}.png`, { path: detailFile, contentType: 'image/png' });
  }
  await saPage.setViewportSize({ width: 1440, height: 900 });
  await saPage.goto(data.paths.teachers);
  await saPage.locator('#teacher-filter-q').fill('teacher-missing-zzzz');
  await saPage.getByRole('button', { name: 'Filtrele' }).click();
  await expect(saPage.getByText('Filtrelere uygun öğretmen bulunamadı.')).toBeVisible();
  await saPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
  await expect(saPage.getByRole('link', { name: 'Detay' }).first()).toBeVisible();
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ]) {
    await saPage.setViewportSize({ width: viewport.width, height: viewport.height });
    const listResponse = await saPage.goto(data.paths.teachers);
    expect(listResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Öğretmenler' })).toBeVisible();
    await expect(saPage.locator('[data-teacher-summary="total"]')).not.toHaveText('0');
    await expect(saPage.locator('.admin-user-person__name').filter({ hasText: 'Uzunad' })).toBeVisible();
    await expect(saPage.getByText('Belirtilmemiş').first()).toBeVisible();
    await expect(saPage.getByText('Onaysız')).toHaveCount(0);
    const listOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-teacher-dir-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(listOverflow.page, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    expect(listOverflow.doc, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    const listHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(listHeight, 2400)),
    });
    const listFile = path.join('artifacts', 'screenshots', `admin-ogretmenler-${viewport.name}.png`);
    await saPage.screenshot({ path: listFile, fullPage: false });
    await info.attach(`admin-ogretmenler-${viewport.name}.png`, { path: listFile, contentType: 'image/png' });

    const detailResponse = await saPage.goto(data.paths.teacher);
    expect(detailResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Öğretmen detayı' })).toBeVisible();
    await expect(saPage.locator('.admin-user-detail-name')).toContainText('Uzunad');
    await expect(saPage.locator('.admin-user-detail-email')).toContainText('browser-detail-');
    await expect(saPage.getByText('Uzman öğretmen')).toBeVisible();
    await expect(saPage.getByText('Zümre başkanı')).toBeVisible();
    await expect(saPage.getByText('Belirtilmemiş').first()).toBeVisible();
    await expect(saPage.getByRole('link', { name: 'Kullanıcı detayı' })).toBeVisible();
    await expect(saPage.getByRole('link', { name: 'İşlem geçmişi' })).toBeVisible();
    await expect(saPage.getByText('Sinif A')).toBeVisible();
    await expect(saPage.getByText('browser-student@example.test')).toHaveCount(0);
    const detailOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-teacher-dir-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(detailOverflow.page, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    expect(detailOverflow.doc, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    const detailHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(detailHeight, 2400)),
    });
    const teacherFile = path.join('artifacts', 'screenshots', `admin-ogretmen-${viewport.name}.png`);
    await saPage.screenshot({ path: teacherFile, fullPage: false });
    await info.attach(`admin-ogretmen-${viewport.name}.png`, { path: teacherFile, contentType: 'image/png' });
  }
  await saPage.setViewportSize({ width: 1440, height: 900 });
  await saPage.goto(data.paths.modulePermissions);
  await saPage.locator('#module-permission-filter-q').fill('module-permission-yok-zzzz');
  await saPage.getByRole('button', { name: 'Filtrele' }).click();
  await expect(saPage.getByText('Filtrelere uygun hesap bulunamadı.')).toBeVisible();
  await saPage.getByRole('link', { name: 'Filtreleri temizle' }).click();
  await expect(saPage.getByRole('link', { name: 'İzinler' }).first()).toBeVisible();
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ]) {
    await saPage.setViewportSize({ width: viewport.width, height: viewport.height });
    const listResponse = await saPage.goto(data.paths.modulePermissions);
    expect(listResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Rol ve modül izinleri' })).toBeVisible();
    await expect(saPage.getByText('Moderatör').first()).toBeVisible();
    await expect(saPage.locator('.admin-user-person__name').filter({ hasText: 'Uzunad' })).toBeVisible();
    const listOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-module-permission-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(listOverflow.page, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    expect(listOverflow.doc, viewport.name).toBeLessThanOrEqual(listOverflow.view + 1);
    const listHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(listHeight, 2400)),
    });
    const listFile = path.join('artifacts', 'screenshots', `admin-rol-yetkileri-${viewport.name}.png`);
    await saPage.screenshot({ path: listFile, fullPage: false });
    await info.attach(`admin-rol-yetkileri-${viewport.name}.png`, { path: listFile, contentType: 'image/png' });

    const detailResponse = await saPage.goto(data.paths.modulePermission);
    expect(detailResponse?.status()).toBe(200);
    await expect(saPage.getByRole('heading', { level: 1, name: 'Modül izinleri' })).toBeVisible();
    await expect(saPage.locator('.admin-user-detail-name')).toContainText('Uzunad');
    await expect(saPage.locator('input[value="question.manage"]')).toHaveCount(1);
    await expect(saPage.locator('input[value="ROLE_ADMIN"]')).toHaveCount(0);
    await expect(saPage.getByText('Yayın ve arşiv bu ekrandan verilmez.').first()).toBeVisible();
    await expect(saPage.getByRole('button', { name: 'Kaydet' })).toBeVisible();
    await expect(saPage.getByRole('link', { name: 'Vazgeç' })).toBeVisible();
    const detailOverflow = await saPage.evaluate(() => {
      const page = document.querySelector('.admin-module-permission-page');
      const view = document.documentElement.clientWidth;
      return { page: page?.scrollWidth ?? 0, doc: document.documentElement.scrollWidth, view };
    });
    expect(detailOverflow.page, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    expect(detailOverflow.doc, viewport.name).toBeLessThanOrEqual(detailOverflow.view + 1);
    const detailHeight = await saPage.evaluate(() => document.documentElement.scrollHeight);
    await saPage.setViewportSize({
      width: viewport.width,
      height: Math.max(viewport.height, Math.min(detailHeight, 2400)),
    });
    const detailFile = path.join('artifacts', 'screenshots', `admin-rol-yetki-${viewport.name}.png`);
    await saPage.screenshot({ path: detailFile, fullPage: false });
    await info.attach(`admin-rol-yetki-${viewport.name}.png`, { path: detailFile, contentType: 'image/png' });
  }
  await saPage.setViewportSize({ width: 1280, height: 900 });
  await saPage.goto('/yonetim');
  await expandAdminGroups(saPage);
  for (const label of ['Genel Bakış', 'Kullanıcılar ve Yetkiler', 'Kurum ve Sınıf', 'Eğitim', 'Medya', 'Etkileşimli Eğitim', 'İletişim', 'Site Yönetimi', 'Ticaret', 'Entegrasyonlar', 'Sistem', 'Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim', 'Şube ve kampüsler']) {
    const node = saPage.getByText(label, { exact: true }).first();
    await node.scrollIntoViewIfNeeded();
    await expect(node).toBeVisible();
  }
  await saPage.locator('.admin-all-sections > summary').click();
  await expect(saPage.locator('.admin-all-sections')).toHaveJSProperty('open', true);
  const learningCard = saPage.locator('article[aria-labelledby="nav-shortcut-learning"]');
  await learningCard.locator('summary').click();
  await expect(learningCard.getByRole('link', { name: 'Soru havuzu' })).toBeVisible();
  await expect(learningCard).toContainText('Çalışıyor');
  await expect(learningCard).toContainText('Geliştirme aşamasında');
  await expect(learningCard.locator('a.admin-media-link')).toHaveCount(0);
  const mediaCard = saPage.locator('article[aria-labelledby="nav-shortcut-media"]');
  const mediaDetails = mediaCard.locator('details');
  await mediaCard.locator('summary').focus();
  await saPage.keyboard.press('Enter');
  await expect(mediaDetails).toHaveJSProperty('open', true);
  await expect(mediaCard.locator('a.admin-media-link')).toHaveCount(3);
  await expect(mediaCard.locator('a button, a a')).toHaveCount(0);
  const mediaHrefs = await mediaCard.locator('a.admin-media-link').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href')));
  expect(mediaHrefs).toEqual(['/yonetim/bolum/dokumanlar', '/yonetim/bolum/videolar', '/yonetim/bolum/medya']);
  const documents = mediaCard.locator('a[href="/yonetim/bolum/dokumanlar"]');
  await expect(documents).toContainText('Dokümanlar');
  await expect(documents).toContainText('PDF ve eğitim materyalleri.');
  await expect(documents).toContainText('Geliştirme aşamasında');
  await expect(mediaCard.locator('a[href="/yonetim/bolum/videolar"]')).toContainText('Ders ve anlatım videoları.');
  await expect(mediaCard.locator('a[href="/yonetim/bolum/medya"]')).toContainText('Dosyalar ve depolama alanı.');
  await mediaCard.locator('summary').focus();
  await saPage.keyboard.press('Enter');
  await expect(mediaDetails).toHaveJSProperty('open', false);
  await mediaCard.locator('summary').click();
  await expect(mediaDetails).toHaveJSProperty('open', true);
  await mediaCard.locator('summary').click();
  await expect(mediaDetails).toHaveJSProperty('open', false);
  const media = saPage.locator('#nav-group-media');
  await media.locator('summary').focus();
  await saPage.keyboard.press('Enter');
  await expect(media).toHaveJSProperty('open', false);
  await saPage.keyboard.press('Enter');
  await expect(media).toHaveJSProperty('open', true);
  for (const viewport of NEWS_VIEWPORTS) {
    await saPage.setViewportSize(viewport);
    await saPage.goto('/yonetim/bolum/simulasyonlar');
    await expect(saPage.getByRole('heading', { level: 1, name: 'Simülasyonlar' })).toBeVisible();
    await expect(saPage.getByText('Geliştirme aşamasında').first()).toBeVisible();
    await assertLayout(saPage);
    await shot(saPage, info, 'superadmin', 'bolum', viewport.name);
  }
  await saPage.setViewportSize({ width: 1280, height: 900 });
  const kurum = await saPage.request.get('/kurum');
  expect(kurum.status()).toBe(403);
  for (const viewport of [...VIEWPORTS, { name: '390', width: 390, height: 844 }, { name: '1440', width: 1440, height: 900 }, { name: '1920', width: 1920, height: 1080 }]) {
    await saPage.setViewportSize(viewport);
    await saPage.goto('/yonetim');
    await assertLayout(saPage);
    await assertOverviewChrome(saPage, viewport.width);
    await shot(saPage, info, 'superadmin', viewport.width === 1920 ? 'dashboard' : 'ozet', viewport.name);
    if ([390, 768, 1440].includes(viewport.width)) {
      const openMedia = saPage.locator('article[aria-labelledby="nav-shortcut-media"]');
      await openMedia.locator('summary').click();
      await expect(openMedia.locator('details')).toHaveJSProperty('open', true);
      await openMedia.scrollIntoViewIfNeeded();
      const overflow = await saPage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow).toBe(false);
      const collided = await openMedia.locator('a.admin-media-link').evaluateAll((nodes) => nodes.some((node) => node.scrollWidth > node.clientWidth + 1));
      expect(collided).toBe(false);
      await saPage.mouse.move(0, 0);
      const surfaces = await openMedia.locator('a.admin-media-link').evaluateAll((nodes) => nodes.map((node) => {
        const style = getComputedStyle(node);
        return { background: style.backgroundColor, border: style.borderTopColor };
      }));
      expect(surfaces).toEqual([
        { background: 'rgb(246, 249, 254)', border: 'rgb(197, 216, 246)' },
        { background: 'rgb(248, 247, 253)', border: 'rgb(217, 206, 246)' },
        { background: 'rgb(243, 252, 251)', border: 'rgb(185, 227, 223)' },
      ]);
      const hovered: { background: string; border: string }[] = [];
      for (const link of await openMedia.locator('a.admin-media-link').all()) {
        await link.hover();
        hovered.push(await link.evaluate((node) => {
          const style = getComputedStyle(node);
          return { background: style.backgroundColor, border: style.borderTopColor };
        }));
      }
      expect(hovered).toEqual([
        { background: 'rgb(234, 242, 255)', border: 'rgb(168, 196, 242)' },
        { background: 'rgb(241, 235, 253)', border: 'rgb(196, 176, 239)' },
        { background: 'rgb(230, 247, 245)', border: 'rgb(143, 212, 206)' },
      ]);
      await saPage.mouse.move(0, 0);
      await shot(saPage, info, 'superadmin', 'medya', viewport.name);
      await openOverviewCards(saPage);
      await expect(saPage.locator('article.admin-directory-card')).toHaveCount(10);
      const pageOverflow = await saPage.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(pageOverflow).toBe(false);
      const peopleCard = saPage.locator('article[aria-labelledby="nav-shortcut-people"]');
      await peopleCard.locator('summary').click();
      await expect(peopleCard.locator('details')).toHaveJSProperty('open', false);
      await peopleCard.locator('summary').focus();
      await saPage.keyboard.press('Enter');
      await expect(peopleCard.locator('details')).toHaveJSProperty('open', true);
      await saPage.keyboard.press('Tab');
      const peopleLink = peopleCard.locator('a.admin-mini-link').first();
      await expect(peopleLink).toBeFocused();
      const focusStyle = await peopleLink.evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(focusStyle).toBe('solid');
      const hrefs = await saPage.locator('article.admin-directory-card a.admin-mini-link, article.admin-directory-card a.admin-media-link').evaluateAll((nodes) => nodes.map((node) => node.getAttribute('href')));
      expect(hrefs).toEqual(expect.arrayContaining([
        '/yonetim/sorular',
        '/yonetim/kullanicilar',
        '/yonetim/rol-yetkileri',
        '/yonetim/bolum/dokumanlar',
        '/yonetim/bolum/videolar',
        '/yonetim/bolum/medya',
        '/yonetim/bolum/operasyon',
        '/yonetim/denetim',
        '/yonetim/odemeler',
      ]));
      await saPage.mouse.move(0, 0);
      if (viewport.width === 1440) {
        await shotFull(saPage, info, 'superadmin-gruplar-1440.png');
      }
      if (viewport.width === 768 || viewport.width === 390) {
        await peopleCard.scrollIntoViewIfNeeded();
        await shot(saPage, info, 'superadmin', 'kisiler', String(viewport.width));
        const systemCard = saPage.locator('article[aria-labelledby="nav-shortcut-system"]');
        await systemCard.scrollIntoViewIfNeeded();
        await shot(saPage, info, 'superadmin', 'sistem', String(viewport.width));
        const siteCard = saPage.locator('article[aria-labelledby="nav-shortcut-site"]');
        await siteCard.scrollIntoViewIfNeeded();
        await shot(saPage, info, 'superadmin', 'site', String(viewport.width));
      }
    }
    if (viewport.width >= 1024) {
      await expect(saPage.locator('#panel-sidebar')).toBeVisible();
      const commerce = saPage.locator('#nav-group-commerce');
      await commerce.scrollIntoViewIfNeeded();
      await expect(commerce).toBeVisible();
      if (viewport.width !== 1920) {
        await shot(saPage, info, 'superadmin', 'operasyon', viewport.name);
      }
    }
  }
  await saPage.setViewportSize({ width: 1280, height: 900 });
  await saPage.goto('/yonetim/denetim');
  await assertLayout(saPage);
  await shot(saPage, info, 'superadmin', 'denetim', '1280');
  await assertClean(saPage);
  await sa.close();
});

test('news drafts stay on the admin screen', async ({ browser }, info) => {
  const data = manifest();
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  installVideoAbort(adminPage);
  await watch(adminPage);
  await login(adminPage, data.users.admin);
  const siteGroup = adminPage.locator('#nav-group-site');
  if ((await siteGroup.getAttribute('open')) === null) {
    await siteGroup.locator('summary').click();
  }
  const link = adminPage.locator('#panel-sidebar a[href="/yonetim/haberler"]');
  await link.scrollIntoViewIfNeeded();
  await expect(link).toBeVisible();
  for (const viewport of NEWS_VIEWPORTS) {
    await adminPage.setViewportSize(viewport);
    await adminPage.goto('/yonetim/haberler');
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Haberler' })).toBeVisible();
    await assertLayout(adminPage);
    await shot(adminPage, info, 'admin', 'haberler', viewport.name);
    await adminPage.goto('/yonetim/haberler/yeni');
    await expect(adminPage.getByRole('heading', { level: 1, name: 'Yeni haber' })).toBeVisible();
    await assertLayout(adminPage);
    await shot(adminPage, info, 'admin', 'haber-form', viewport.name);
  }
  await adminPage.setViewportSize({ width: 1280, height: 900 });
  await adminPage.goto('/yonetim/haberler');
  await adminPage.getByLabel('Kategori adı').fill('Tarayici Kategori');
  await adminPage.getByRole('button', { name: 'Kategoriyi kaydet' }).click();
  await expect(adminPage.getByText('Tarayici Kategori')).toBeVisible();
  await adminPage.goto('/yonetim/haberler/yeni');
  await adminPage.getByLabel('Başlık').fill('Tarayici Taslak Haber');
  await adminPage.getByLabel('Özet').fill('Tarayici taslak ozeti sitede gorunmez.');
  await adminPage.getByLabel('Metin').fill('Tarayici taslak metni yalnizca yonetimde kalir.');
  await adminPage.getByLabel('Kategori').selectOption({ label: 'Tarayici Kategori' });
  await adminPage.getByRole('button', { name: 'Taslağı kaydet' }).click();
  await expect(adminPage.getByRole('heading', { level: 1, name: 'Tarayici Taslak Haber' })).toBeVisible();
  await shot(adminPage, info, 'admin', 'haber-taslak', '1280');

  const anon = await browser.newContext();
  const page = await anon.newPage();
  installVideoAbort(page);
  await watch(page);
  for (const viewport of NEWS_VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/haberler');
    await expect(page.getByRole('heading', { level: 1, name: 'Haberler' })).toBeVisible();
    await expect(page.getByText('Tarayici Taslak Haber')).toHaveCount(0);
    await assertLayout(page);
    await shot(page, info, 'public', 'haberler', viewport.name);
  }
  await page.goto('/');
  await expect(page.locator('#haberler')).not.toContainText('Tarayici Taslak Haber');
  expect((await page.request.get('/haberler/tarayici-taslak-haber')).status()).toBe(404);
  await assertClean(page);
  await anon.close();
  await assertClean(adminPage);
  await admin.close();
});

async function assertOverviewChrome(page: import('@playwright/test').Page, width: number): Promise<void> {
  const chrome = await page.evaluate(() => {
    const navEl = document.querySelector('.panel-sidebar__nav');
    const side = document.querySelector('.panel-sidebar');
    if (!(navEl instanceof HTMLElement) || !(side instanceof HTMLElement)) {
      return null;
    }
    return {
      scroll: navEl.scrollTop,
      navOverflow: getComputedStyle(navEl).overflowY,
      sideOverflow: getComputedStyle(side).overflowY,
    };
  });
  expect(chrome).not.toBeNull();
  expect(chrome!.scroll).toBe(0);
  expect(chrome!.navOverflow).toBe('auto');
  expect(chrome!.sideOverflow).toBe('hidden');
  await expect(page.locator('.admin-search, .admin-icon-btn')).toHaveCount(0);
  await expect(page.getByRole('heading', { level: 1, name: 'Genel Bakış' })).toBeVisible();

  if (width >= 1024) {
    await expect(page.locator('#nav-group-overview')).toBeVisible();
    const genel = await page.locator('#nav-group-overview').boundingBox();
    const navBox = await page.locator('.panel-sidebar__nav').boundingBox();
    expect(genel).not.toBeNull();
    expect(navBox).not.toBeNull();
    expect(genel!.y).toBeGreaterThanOrEqual(navBox!.y - 1);
    expect(genel!.y).toBeLessThan(navBox!.y + navBox!.height);
    const avatar = await page.locator('.admin-topbar-initials').boundingBox();
    const bar = await page.locator('.panel-topbar').boundingBox();
    const side = await page.locator('#panel-sidebar').boundingBox();
    const foot = await page.locator('.admin-sidebar__foot').boundingBox();
    expect(avatar && bar && side && foot).toBeTruthy();
    expect(avatar!.y).toBeGreaterThanOrEqual(bar!.y - 1);
    expect(avatar!.y + avatar!.height).toBeLessThanOrEqual(bar!.y + bar!.height + 1);
    expect(bar!.height).toBeLessThan(88);
    expect(foot!.y).toBeGreaterThan(navBox!.y);
    expect(foot!.y + foot!.height).toBeLessThanOrEqual(side!.y + side!.height + 1);
  }
  if (width >= 1280) {
    const tops = await page.locator('.admin-metric-grid .admin-metric').evaluateAll((nodes) =>
      nodes.map((node) => node.getBoundingClientRect().top),
    );
    expect(tops).toHaveLength(4);
    expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(8);
    const compact = await page.locator('.admin-directory--featured .admin-directory-card').evaluateAll((nodes) =>
      nodes.map((node) => node.getBoundingClientRect().height),
    );
    expect(compact.length).toBeGreaterThan(0);
    expect(Math.max(...compact)).toBeLessThan(160);
    const font = await page.locator('h1').evaluate((node) => getComputedStyle(node).fontFamily);
    expect(font.toLowerCase()).not.toContain('georgia');
    expect(font.toLowerCase()).not.toContain('times');
  }
}

async function expandAdminGroups(page: import('@playwright/test').Page): Promise<void> {
  const groups = page.locator('#panel-sidebar details.panel-nav-group');
  const count = await groups.count();
  for (let index = 0; index < count; index += 1) {
    const group = groups.nth(index);
    if ((await group.getAttribute('open')) === null) {
      await group.locator('summary').click();
    }
  }
}

async function openOverviewCards(page: Page): Promise<void> {
  const all = page.locator('.admin-all-sections');
  if ((await all.getAttribute('open')) === null) {
    await page.locator('.admin-all-sections > summary').click();
  }
  await expect(all).toHaveJSProperty('open', true);
  const cards = page.locator('article.admin-directory-card');
  const count = await cards.count();
  expect(count).toBeGreaterThan(0);
  for (let index = 0; index < count; index += 1) {
    const card = cards.nth(index);
    const details = card.locator('details');
    if ((await details.getAttribute('open')) === null) {
      await card.locator('summary.admin-directory-card__head').click();
    }
    await expect(details).toHaveJSProperty('open', true);
    const links = card.locator('a.admin-mini-link, a.admin-media-link');
    expect(await links.count()).toBeGreaterThan(0);
    const collided = await links.evaluateAll((nodes) => nodes.some((node) => {
      if (!(node instanceof HTMLElement) || node.scrollWidth > node.clientWidth + 1) {
        return true;
      }
      if (!node.classList.contains('admin-mini-link')) {
        return false;
      }
      const copy = node.querySelector('.admin-mini-link__copy, .admin-media-link__copy');
      const icon = node.querySelector('.admin-mini-link__icon, .admin-media-link__icon');
      const arrow = node.querySelector('.admin-mini-link__arrow, .admin-media-link__arrow');
      if (!(copy instanceof HTMLElement) || !(icon instanceof HTMLElement) || !(arrow instanceof HTMLElement)) {
        return true;
      }
      const copyBox = copy.getBoundingClientRect();
      const iconBox = icon.getBoundingClientRect();
      const arrowBox = arrow.getBoundingClientRect();
      if (copyBox.left < iconBox.right - 1 || copyBox.right > arrowBox.left + 1) {
        return true;
      }
      return Array.from(node.querySelectorAll('.admin-mini-link__title, .admin-mini-link__blurb, .admin-mini-link__stage, .admin-media-link__title, .admin-media-link__blurb, .admin-media-link__stage')).some((text) => text instanceof HTMLElement && text.scrollWidth > text.clientWidth + 1);
    }));
    expect(collided).toBe(false);
  }
}

async function shotFull(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: true });
  await info.attach(name, { path: file, contentType: 'image/png' });
}

async function closeDrawer(page: import('@playwright/test').Page): Promise<void> {
  const menu = page.getByRole('button', { name: 'Menü', exact: true });
  if (!(await menu.isVisible())) {
    return;
  }
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await menu.click();
  const systemGroup = page.locator('#nav-group-system');
  if ((await systemGroup.getAttribute('open')) === null) {
    await systemGroup.locator('summary').click();
  }
  await page.locator('#nav-group-system a.panel-nav-link', { hasText: 'Sistem durumu' }).click();
  await expect(page).toHaveURL(/\/yonetim\/sistem/);
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await page.goto('/yonetim');
  await menu.click();
  const sidebar = await page.locator('#panel-sidebar').boundingBox();
  const backdrop = page.locator('.admin-nav-backdrop');
  const overlay = await backdrop.boundingBox();
  const header = await page.locator('.panel-topbar').boundingBox();
  if (sidebar && overlay && header && overlay.width - sidebar.width > 12) {
    await backdrop.click({
      position: { x: sidebar.width + 8, y: header.y + header.height + 16 },
      timeout: 5_000,
    });
  }
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
}
