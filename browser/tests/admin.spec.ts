import { expect, test } from '@playwright/test';
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
  for (const label of ['Genel Bakış', 'Kullanıcılar ve Yetkiler', 'Kurum ve Sınıf', 'Eğitim', 'Medya', 'Etkileşimli Eğitim', 'İletişim', 'Site Yönetimi', 'Ticaret', 'Entegrasyonlar', 'Sistem', 'Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim', 'Şube ve kampüsler']) {
    const node = saPage.getByText(label, { exact: true }).first();
    await node.scrollIntoViewIfNeeded();
    await expect(node).toBeVisible();
  }
  await saPage.locator('.admin-all-sections > summary').click();
  await expect(saPage.locator('.admin-all-sections')).toHaveJSProperty('open', true);
  const learningCard = saPage.locator('article[aria-labelledby="nav-shortcut-learning"]');
  await learningCard.locator('summary').click();
  await expect(learningCard.getByRole('link', { name: 'Soru havuzu', exact: true })).toBeVisible();
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
