import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

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
  for (const label of ['Genel Bakış', 'Sistem', 'Kullanıcılar', 'Kurumlar', 'Müfredat', 'İçerikler', 'Sorular', 'Testler']) {
    await expect(adminPage.getByRole('link', { name: label }).first()).toBeVisible();
  }
  for (const label of ['Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim']) {
    await expect(adminPage.getByRole('link', { name: label })).toHaveCount(0);
  }
  await expect(adminPage.locator('a.panel-nav-link[aria-current="page"]')).toHaveText('Genel Bakış');
  for (const viewport of [...VIEWPORTS, { name: '1920', width: 1920, height: 1080 }]) {
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
  await expect(adminPage.getByRole('heading', { level: 1, name: /Günaydın .+ sistem hazır/ })).toBeVisible();
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
  for (const label of ['Genel', 'Yönetim', 'Eğitim', 'Operasyon', 'Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim']) {
    await expect(saPage.getByText(label).first()).toBeVisible();
  }
  const kurum = await saPage.request.get('/kurum');
  expect(kurum.status()).toBe(403);
  for (const viewport of [...VIEWPORTS, { name: '1920', width: 1920, height: 1080 }]) {
    await saPage.setViewportSize(viewport);
    await saPage.goto('/yonetim');
    await assertLayout(saPage);
    await assertOverviewChrome(saPage, viewport.width);
    await shot(saPage, info, 'superadmin', viewport.width === 1920 ? 'dashboard' : 'ozet', viewport.name);
    if (viewport.width >= 1024) {
      await expect(saPage.locator('#panel-sidebar')).toBeVisible();
      await expect(saPage.locator('#nav-group-operations')).toBeVisible();
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

  if (width >= 1024) {
    await expect(page.locator('#nav-group-general')).toBeVisible();
    const genel = await page.locator('#nav-group-general').boundingBox();
    const navBox = await page.locator('.panel-sidebar__nav').boundingBox();
    expect(genel).not.toBeNull();
    expect(navBox).not.toBeNull();
    expect(genel!.y).toBeGreaterThanOrEqual(navBox!.y - 1);
    expect(genel!.y).toBeLessThan(navBox!.y + navBox!.height);
    const search = await page.locator('.admin-search input').boundingBox();
    const bell = await page.locator('.admin-icon-btn').boundingBox();
    const avatar = await page.locator('.admin-topbar-initials').boundingBox();
    const bar = await page.locator('.panel-topbar').boundingBox();
    expect(search && bell && avatar && bar).toBeTruthy();
    expect(Math.abs(search!.y - bell!.y)).toBeLessThan(16);
    expect(Math.abs(bell!.y - avatar!.y)).toBeLessThan(12);
    expect(bar!.height).toBeLessThan(88);
    await expect(page.locator('.admin-search input')).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('.admin-icon-btn')).toHaveAttribute('tabindex', '-1');
  }
  if (width >= 1280) {
    const tops = await page.locator('.admin-metric').evaluateAll((nodes) =>
      nodes.map((node) => node.getBoundingClientRect().top),
    );
    expect(tops).toHaveLength(6);
    expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(8);
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
  await page.locator('#panel-sidebar a.panel-nav-link', { hasText: 'Sistem' }).click();
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
