import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, manifest, shot, watch } from './support';

const SIZES = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 900 },
] as const;

test.beforeEach(async ({ page }) => {
  await watch(page);
});

for (const size of SIZES) {
  test(`unit CTA returns the student to the unit after login at ${size.name}`, async ({ page }, info) => {
    const data = manifest();
    const unitPath = data.paths.publicUnit.replace('/dersler/1/', '/ogrenci/dersler/');
    await page.setViewportSize({ width: size.width, height: size.height });

    // Catalog walk: index -> grade -> subject -> unit.
    await page.goto(data.paths.publicCatalog);
    await assertLayout(page);
    await page.goto(data.paths.publicGrade);
    await assertLayout(page);
    await page.goto(data.paths.publicSubject);
    await page.locator(`main a[href="${data.paths.publicUnit}"]`).first().click();
    await expect(page).toHaveURL(data.paths.publicUnit);
    await assertLayout(page);

    // The unit-level CTA is reachable from the keyboard and carries only catalog coordinates.
    const cta = page.locator('aside.catalog-cta').getByRole('link', { name: 'Giriş yap' });
    await expect(cta).toBeVisible();
    await cta.focus();
    await expect(cta).toBeFocused();
    const href = (await cta.getAttribute('href')) ?? '';
    expect(href.startsWith('/giris?sinif=1&ders=')).toBe(true);
    expect(href).not.toContain('http');
    await assertClean(page);
    await shot(page, info, 'catalog-return', 'unite', size.name);

    await cta.click();
    await expect(page).toHaveURL(/\/giris\?/);
    await assertLayout(page);
    await expect(page.locator('[data-login-resume]')).toBeVisible();
    await expect(page.getByLabel('E-posta')).toBeVisible();
    await expect(page.getByLabel('Parola')).toBeVisible();
    await shot(page, info, 'catalog-return', 'giris', size.name);

    if (size.name === '390') {
      // A wrong password keeps the form usable and the remembered unit.
      await page.getByLabel('E-posta').fill(data.users.student);
      await page.getByLabel('Parola').fill('yanlis-parola-123');
      await page.getByRole('button', { name: 'Giriş yap' }).click();
      await expect(page.getByRole('alert')).toBeVisible();
      await expect(page.locator('[data-login-resume]')).toBeVisible();
    }

    await page.getByLabel('E-posta').fill(data.users.student);
    await page.getByLabel('Parola').fill(data.password);
    await page.getByRole('button', { name: 'Giriş yap' }).click();

    await expect(page).toHaveURL(unitPath);
    await assertLayout(page);
    await expect(page).toHaveTitle(/Sayilar/);
    await expect(page.getByRole('heading', { level: 1, name: 'Sayilar' })).toBeVisible();
    await shot(page, info, 'catalog-return', 'ogrenci-unite', size.name);
  });
}

test('topic CTA returns the student to the topic after login', async ({ page }, info) => {
  const data = manifest();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(data.paths.publicUnit);
  await page.locator('.catalog-list').getByRole('link', { name: 'Giriş yap' }).first().click();
  await expect(page.locator('[data-login-resume]')).toBeVisible();
  await page.getByLabel('E-posta').fill(data.users.student);
  await page.getByLabel('Parola').fill(data.password);
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  await expect(page).toHaveURL(data.paths.topic);
  await assertLayout(page);
  await shot(page, info, 'catalog-return', 'ogrenci-konu', '390');
});

test('direct login still lands on the student panel', async ({ page }) => {
  const data = manifest();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/giris');
  await expect(page.locator('[data-login-resume]')).toHaveCount(0);
  await page.getByLabel('E-posta').fill(data.users.student);
  await page.getByLabel('Parola').fill(data.password);
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  await expect(page).toHaveURL(/\/ogrenci$/);
});
