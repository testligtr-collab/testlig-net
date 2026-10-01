import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, manifest, shot, VIEWPORTS, watch } from './support';

test.beforeEach(async ({ page }) => {
  await watch(page);
});

test('public catalog shows published names and hides lesson bodies', async ({ page }, info) => {
  const paths = manifest().paths;
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const index = await page.goto(paths.publicCatalog);
    expect(index?.status()).toBe(200);
    expect(index?.headers()['x-robots-tag'] ?? '').not.toContain('noindex');
    await expect(page.getByRole('heading', { level: 1, name: 'Dersleri keşfet' })).toBeVisible();
    await assertLayout(page);
    await assertClean(page);
    await shot(page, info, 'public', 'dersler', viewport.name);

    await page.goto(paths.publicGrade);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await assertLayout(page);
    await shot(page, info, 'public', 'ders-sinif', viewport.name);

    await page.goto(paths.publicSubject);
    await expect(page.getByRole('link', { name: 'Dersi incele' }).or(page.getByRole('link', { name: 'Üniteyi incele' })).first()).toBeVisible();
    await assertLayout(page);
    await shot(page, info, 'public', 'ders-detay', viewport.name);

    await page.goto(paths.publicUnit);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ücretsiz hesap oluştur' }).first()).toBeVisible();
    await expect(page.getByText('Toplama anlatimi')).toHaveCount(0);
    await expect(page.getByText('Iki arti iki kactir')).toHaveCount(0);
    await expect(page.getByText('Gizli metin')).toHaveCount(0);
    await assertLayout(page);
    await assertClean(page);
    await shot(page, info, 'public', 'unite', viewport.name);
  }

  const draft = await page.goto(paths.publicDraft);
  expect(draft?.status()).toBe(404);
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

  const sitemap = await page.request.get('/sitemap.xml');
  expect(sitemap.status()).toBe(200);
  expect(sitemap.headers()['content-type'] ?? '').toContain('xml');
  const xml = await sitemap.text();
  expect(xml).toContain(paths.publicSubject);
  expect(xml).not.toContain('taslak-ders');
  expect(xml).not.toContain('/ogrenci');

  await page.goto(paths.publicUnit);
  await page.getByRole('link', { name: 'Ücretsiz hesap oluştur' }).first().click();
  await expect(page).toHaveURL(/\/kayit/);
});
