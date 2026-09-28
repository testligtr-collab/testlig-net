import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('admin and superadmin menus stay inside their roles', async ({ browser }, info) => {
  const data = manifest();
  const admin = await browser.newContext();
  const adminPage = await admin.newPage();
  installVideoAbort(adminPage);
  await watch(adminPage);
  await login(adminPage, data.users.admin);
  await adminPage.goto('/yonetim');
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
    await shot(adminPage, info, 'admin', 'ozet', viewport.name);
    if (viewport.width < 1024) {
      await closeDrawer(adminPage);
    }
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
  await expect(saPage.getByText('Süper Yönetici')).toBeVisible();
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
