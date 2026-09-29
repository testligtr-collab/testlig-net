import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('student lesson, blocked video host, and one test attempt', async ({ page, browser }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.student);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const home = await page.goto('/ogrenci');
  expect(home?.headers()['cache-control'] ?? '').toContain('no-store');
  expect(home?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(page.getByRole('link', { name: 'Çocuklarım' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Ödemeler' })).toHaveCount(0);
  await expect(page.locator('a[aria-current="page"]', { hasText: 'Panel' }).first()).toBeVisible();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/ogrenci');
    await assertLayout(page);
    await shot(page, info, 'student', 'panel', viewport.name);
    if (viewport.width === 360) {
      const toggle = page.getByRole('button', { name: 'Menüyü aç veya kapat' });
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await page.keyboard.press('Escape');
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      const box = await toggle.boundingBox();
      expect(box).not.toBeNull();
      expect(box!.width).toBeGreaterThanOrEqual(44);
      expect(box!.height).toBeGreaterThanOrEqual(44);
    }
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/ogrenci/dersler');
  await assertLayout(page);
  await shot(page, info, 'student', 'dersler', '1280');
  await page.getByRole('link', { name: 'Matematik' }).click();
  await assertLayout(page);
  await shot(page, info, 'student', 'ders', '1280');
  await page.getByRole('link', { name: 'Sayilar' }).click();
  await assertLayout(page);
  await shot(page, info, 'student', 'unite', '1280');
  await page.getByRole('link', { name: 'Toplama' }).click();
  await expect(page).toHaveURL(data.paths.topic);
  await expect(page.getByText('Iki sayiyi toplariz')).toBeVisible();
  await expect(page.getByText('Once onluklari yaz')).toBeVisible();
  await expect(page.getByText('2 + 2 = 4')).toBeVisible();
  const frame = page.locator('iframe');
  await expect(frame).toHaveAttribute('src', /youtube-nocookie\.com/);
  await expect(page.getByRole('link', { name: /PDF/ })).toHaveAttribute('href', /\/pdf\//);
  const html = await page.content();
  expect(html).not.toMatch(/storageKey|ciphertext|correctStableKey/i);
  expect(html).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
  const topicResponse = await page.goto(data.paths.topic);
  expect(topicResponse?.headers()['cache-control'] ?? '').toContain('no-store');
  expect(topicResponse?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await assertClean(page);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.topic);
    await assertLayout(page);
    await shot(page, info, 'student', 'konu', viewport.name);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/ogrenci/testler');
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/ogrenci/testler');
    await assertLayout(page);
    await shot(page, info, 'student', 'testler', viewport.name);
  }

  await page.getByRole('link', { name: data.paths.testTitle }).click();
  await page.getByRole('button', { name: 'Teste başla' }).click();
  await assertLayout(page);
  await shot(page, info, 'student', 'coz', '1280');
  await page.getByRole('radio').first().check();
  await page.getByRole('button', { name: 'Kaydet' }).click();
  await expect(page.getByText('Kaydedildi')).toBeVisible();
  const solveHtml = await page.content();
  expect(solveHtml).not.toContain('correctStableKey');
  expect(solveHtml).not.toContain('opt_b');
  await page.getByLabel('Testi bitirmek istiyorum').check();
  await page.getByRole('button', { name: 'Testi bitir' }).click();
  await page.waitForURL(/\/sonuc$/);
  const resultHtml = await page.content();
  expect(resultHtml).not.toContain('correctStableKey');
  expect(resultHtml).not.toContain('opt_b');
  await shot(page, info, 'student', 'sonuc', '1280');
  await page.goto('/ogrenci/testler/gecmisim');
  await expect(page.getByText(data.paths.testTitle)).toBeVisible();
  await shot(page, info, 'student', 'gecmis', '1280');
  await page.goto('/ogrenci/profil');
  await expect(page.getByRole('heading', { level: 1, name: 'Profilim' })).toBeVisible();
  await shot(page, info, 'student', 'profil', '1280');
  await assertClean(page);

  const other = await browser.newContext();
  const otherPage = await other.newPage();
  installVideoAbort(otherPage);
  await watch(otherPage);
  await login(otherPage, data.users.otherStudent);
  const missing = await otherPage.goto(`/ogrenci/testler/${data.paths.testCode}/sonuc`);
  expect(missing?.status()).toBe(404);
  await other.close();
});
