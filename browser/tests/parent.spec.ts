import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('parent sees only the linked student summary', async ({ page }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.parent);
  await expect(page).toHaveURL(/\/veli$/);
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
  const missing = await page.goto('/veli/cocuk/aaaaaaaaaaaaaaaaaaaa');
  expect(missing?.status()).toBe(404);
  const start = await page.goto(`/ogrenci/testler/${data.paths.testCode}`);
  expect([302, 403]).toContain(start?.status() ?? 0);
  await assertClean(page);
});
