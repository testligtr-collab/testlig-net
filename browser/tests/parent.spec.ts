import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('parent sees only the linked student summary', async ({ page, browser }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.parent);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await expect(page).toHaveURL(/\/veli$/);
  await expect(page.getByRole('link', { name: 'Teste başla' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Dersler' })).toHaveCount(0);
  await expect(page.getByText('Deneme Ogrenci')).toBeVisible();
  const html = await page.content();
  expect(html).not.toContain('@example.test');
  expect(html).not.toMatch(/okul adı|şehir|öğrenme hedefi|correctStableKey/i);
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/veli');
    const response = await page.goto('/veli');
    expect(response?.headers()['cache-control'] ?? '').toContain('no-store');
    expect(response?.headers()['x-robots-tag'] ?? '').toContain('noindex');
    expect(response?.headers()['referrer-policy'] ?? '').toContain('no-referrer');
    await assertLayout(page);
    await shot(page, info, 'parent', 'ozet', viewport.name);
  }
  await page.goto('/veli/baglan');
  await expect(page.locator('#parent-link-code')).toHaveValue('');
  await shot(page, info, 'parent', 'baglanti', '1280');
  await page.goto('/veli');
  const empty = await browser.newContext();
  const emptyPage = await empty.newPage();
  installVideoAbort(emptyPage);
  await watch(emptyPage);
  await login(emptyPage, data.users.parentUnlinked);
  await emptyPage.setViewportSize({ width: 1280, height: 900 });
  await emptyPage.goto('/veli');
  await expect(emptyPage).toHaveURL(/\/veli\/baglan/);
  await expect(emptyPage.locator('#parent-link-code')).toHaveValue('');
  await shot(emptyPage, info, 'parent', 'bos', '1280');
  await assertClean(emptyPage);
  await empty.close();
  await assertClean(page);
  const missing = await page.goto('/veli/cocuk/aaaaaaaaaaaaaaaaaaaa');
  expect(missing?.status()).toBe(404);
  const start = await page.goto(`/ogrenci/testler/${data.paths.testCode}`);
  expect([302, 403]).toContain(start?.status() ?? 0);
});
