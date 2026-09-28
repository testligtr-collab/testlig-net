import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('student lesson, blocked video host, and one test attempt', async ({ page, browser }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.student);
  await expect(page).toHaveURL(/\/ogrenci$/);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/ogrenci');
    await assertLayout(page);
    await shot(page, info, 'student', 'panel', viewport.name);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/ogrenci/dersler');
  await page.getByRole('link', { name: 'Matematik' }).click();
  await page.getByRole('link', { name: 'Sayilar' }).click();
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
  await page.goto('/ogrenci/testler/gecmisim');
  await expect(page.getByText(data.paths.testTitle)).toBeVisible();
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
