import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('admin and superadmin menus stay inside their roles', async ({ browser }, info) => {
  const data = manifest();
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  installVideoAbort(adminPage);
  await watch(adminPage);
  await login(adminPage, data.users.admin);
  await adminPage.emulateMedia({ reducedMotion: 'reduce' });
  const dashboard = await adminPage.goto('/yonetim');
  expect(dashboard?.headers()['cache-control'] ?? '').toContain('no-store');
  expect(dashboard?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(adminPage.locator('.panel-role')).toHaveText('Yönetici');
  for (const label of ['Özet', 'Sistem', 'Kullanıcılar', 'Kurumlar', 'Müfredat', 'İçerikler', 'Sorular', 'Testler']) {
    await expect(adminPage.getByRole('link', { name: label }).first()).toBeVisible();
  }
  for (const label of ['Ödemeler', 'Webhook', 'Uzlaştırma', 'Denetim']) {
    await expect(adminPage.getByRole('link', { name: label })).toHaveCount(0);
  }
  await expect(adminPage.locator('a.panel-nav-link[aria-current="page"]')).toHaveText('Özet');
  for (const viewport of VIEWPORTS) {
    await adminPage.setViewportSize(viewport);
    await adminPage.goto('/yonetim');
    await assertLayout(adminPage);
    const primary = adminPage.getByRole('link', { name: 'Yeni içerik' });
    if (await primary.count()) {
      const primaryBox = await primary.first().boundingBox();
      expect(primaryBox).not.toBeNull();
      expect(primaryBox!.height).toBeGreaterThanOrEqual(44);
    }
    if (viewport.width >= 1024) {
      await expect(adminPage.locator('#panel-sidebar')).toBeVisible();
    }
    await shot(adminPage, info, 'admin', 'ozet', viewport.name);
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
  await expect(adminPage.getByRole('heading', { level: 1, name: 'Yönetim özeti' })).toBeVisible();
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
  for (const viewport of VIEWPORTS) {
    await saPage.setViewportSize(viewport);
    await saPage.goto('/yonetim');
    await assertLayout(saPage);
    await shot(saPage, info, 'superadmin', 'ozet', viewport.name);
    if (viewport.width >= 1024) {
      await expect(saPage.locator('#panel-sidebar')).toBeVisible();
      await shot(saPage, info, 'superadmin', 'operasyon', viewport.name);
    }
  }
  await assertClean(saPage);
  await sa.close();
});

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
