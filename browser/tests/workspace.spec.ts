import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, login, manifest, shot, watch } from './support';

test('content workspace roles stay inside their own roots', async ({ page, browser }, info) => {
  await watch(page);
  const data = manifest();

  const anonymous = await browser.newContext();
  const guest = await anonymous.newPage();
  await guest.goto('/calisma-alani');
  await expect(guest).toHaveURL(/\/giris$/);
  await anonymous.close();

  await login(page, data.users.teacher);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const home = await page.goto('/calisma-alani');
  expect(home?.status()).toBe(200);
  await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  const denied = await page.request.get('/yonetim');
  expect(denied.status()).toBe(403);
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/calisma-alani');
  const toggle = page.getByRole('button', { name: 'Menü', exact: true });
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await assertLayout(page);

  await login(page, data.users.moderator);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/calisma-alani');
  await expect(page.locator('#review-queue')).toBeVisible();
  await expect(page.getByRole('link', { name: 'İnceleme kuyruğu' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Yeni içerik' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Yeni soru' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Yeni test' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'PDF onayı' })).toHaveCount(0);
  await assertLayout(page);
  await shot(page, info, 'moderator', 'calisma-alani', '1280');

  await login(page, data.users.expert);
  await page.goto('/calisma-alani');
  await expect(page.getByRole('link', { name: 'Yeni içerik' })).toBeVisible();
  await expect(page.locator('.panel-role')).toHaveText('Uzman Öğretmen');
  await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await assertLayout(page);
  await shot(page, info, 'expert', 'calisma-alani', '1280');

  await login(page, data.users.emptyTeacher);
  await page.goto('/calisma-alani');
  await expect(page.locator('[data-metric="drafts"] .workspace-metric__value')).toHaveText('0');
  await expect(page.locator('[data-metric="classrooms"] .workspace-metric__value')).toHaveText('0');
  await expect(page.getByText(data.paths.foreignTitle)).toHaveCount(0);
  await expect(page.getByText('Güncel içerik yok')).toBeVisible();
  await assertLayout(page);
  await shot(page, info, 'teacher-empty', 'calisma-alani', '1280');

  await login(page, data.users.admin);
  await page.goto('/yonetim');
  await expect(page.getByRole('link', { name: 'Özet', exact: true })).toHaveAttribute('href', '/yonetim');
  const adminWorkspace = await page.request.get('/calisma-alani');
  expect(adminWorkspace.status()).toBe(200);

  await login(page, data.users.superadmin);
  await page.goto('/yonetim');
  await expect(page.getByRole('link', { name: 'Özet', exact: true })).toHaveAttribute('href', '/yonetim');

  await login(page, data.users.student);
  expect((await page.request.get('/calisma-alani')).status()).toBe(403);
  await login(page, data.users.parent);
  expect((await page.request.get('/calisma-alani')).status()).toBe(403);
  await login(page, data.users.owner);
  expect((await page.request.get('/calisma-alani')).status()).toBe(403);

  await assertClean(page);
});
