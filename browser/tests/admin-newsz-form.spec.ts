import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin news create and edit form layout, errors, and long text', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/haberler/yeni');
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni haber' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Haber içeriği' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Kısa yardım' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslağı kaydet' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Listeye dön|İptal/ }).first()).toBeVisible();
    const save = page.getByRole('button', { name: 'Taslağı kaydet' });
    const saveBox = await save.boundingBox();
    expect(saveBox).toBeTruthy();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-haber-form-bos-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/haberler');
  await page.locator('#news-category-name').fill('Form Kategori');
  await page.getByRole('button', { name: 'Kategori ekle' }).click();
  await page.waitForURL('/yonetim/haberler');

  const draftPath = await createDraft(
    page,
    'Duzenleme formu basligi',
    'Form Kategori',
    'Duzenleme formu ozeti burada durur.',
    'Duzenleme formu govdesi burada durur ve yeterlidir.',
  );
  const editPath = `${draftPath}/duzenle`;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(editPath);
    await expect(page.getByRole('heading', { level: 1, name: 'Haberi düzenle' })).toBeVisible();
    await expect(page.locator('#news-title')).toHaveValue('Duzenleme formu basligi');
    await expect(page.locator('#news-summary')).toHaveValue('Duzenleme formu ozeti burada durur.');
    await expect(page.locator('#news-body')).toHaveValue('Duzenleme formu govdesi burada durur ve yeterlidir.');
    await expect(page.getByRole('button', { name: 'Taslağı güncelle' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Habere dön|İptal/ }).first()).toBeVisible();
    const mainBox = await page.locator('.admin-news-form-card').boundingBox();
    const asideBox = await page.locator('.admin-news-form-aside').boundingBox();
    expect(mainBox && asideBox).toBeTruthy();
    if (viewport.width >= 1024) {
      expect(asideBox!.height).toBeLessThan(mainBox!.height + 120);
    } else {
      expect(asideBox!.y).toBeGreaterThan(mainBox!.y);
    }
    await assertLayout(page);
    await capture(page, info, `admin-haber-form-duzenle-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/haberler/yeni');
    await page.locator('#news-title').fill('Hatali form basligi');
    await page.locator('#news-summary').fill('Hatali form ozeti.');
    await page.locator('#news-body').fill('Hatali form govdesi <b>etiket</b> tasir.');
    await page.getByRole('button', { name: 'Taslağı kaydet' }).click();
    await expect(page.getByText('HTML kullanılamaz.')).toBeVisible();
    await expect(page.locator('#news-title')).toHaveValue('Hatali form basligi');
    await expect(page.locator('#news-summary')).toHaveValue('Hatali form ozeti.');
    await expect(page.locator('#news-body')).toHaveValue('Hatali form govdesi <b>etiket</b> tasir.');
    await assertLayout(page);
    await capture(page, info, `admin-haber-form-hata-${viewport.name}.png`);
  }

  const longTitle = 'Uzun haber basligi form kartinda ve mobil kolonunda kirilmadan sarilir ve yatay tasmadan devam eder';
  const longSummary = 'Uzun ozet metni form alaninda korunur ve dar ekranda kart kenarindan tasmaz cunku min-width sifir ve overflow-wrap anywhere kullanilir.';
  const longBody = `${'Uzun govde satiri form textarea icinde korunur. '.repeat(12)}Son satir burada biter.`;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(editPath);
    await page.locator('#news-title').fill(longTitle);
    await page.locator('#news-summary').fill(longSummary);
    await page.locator('#news-body').fill(longBody);
    await expect(page.locator('#news-title')).toHaveValue(longTitle);
    await expect(page.locator('#news-summary')).toHaveValue(longSummary);
    await expect(page.locator('#news-body')).toHaveValue(longBody);
    await assertLayout(page);
    await capture(page, info, `admin-haber-form-uzun-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/haberler/yeni');
  const title = page.locator('#news-title');
  await title.focus();
  expect(await title.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await expect(page.locator('label[for="news-title"]')).toBeVisible();
  await expect(page.locator('label[for="news-summary"]')).toBeVisible();
  await expect(page.locator('label[for="news-category"]')).toBeVisible();
  await expect(page.locator('label[for="news-body"]')).toBeVisible();

  const notes = (page as Page & { __notes?: string[] }).__notes ?? [];
  let ignoredValidationResponses = 0;
  (page as Page & { __notes?: string[] }).__notes = notes.filter((note) => {
    if (ignoredValidationResponses < 4 && note.includes('status of 422')) {
      ignoredValidationResponses += 1;

      return false;
    }

    return true;
  });
  expect(ignoredValidationResponses).toBe(4);
  await assertClean(page);
});

async function createDraft(page: Page, title: string, categoryLabel: string, summary: string, body: string): Promise<string> {
  await page.goto('/yonetim/haberler/yeni');
  await page.locator('#news-title').fill(title);
  await page.locator('#news-summary').fill(summary);
  await page.locator('#news-body').fill(body);
  await page.locator('#news-category').selectOption({ label: categoryLabel });
  await page.getByRole('button', { name: 'Taslağı kaydet' }).click();
  await page.waitForURL(/\/yonetim\/haberler\/[0-9a-fA-F-]+$/);

  return new URL(page.url()).pathname;
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 2400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
