import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const longTitle = 'Uzun platform testi basligi dar ekranda kartta ve masaustunde teknik kodun yaninda sarilir ve tasmadan devam eder.';

test('admin test create and edit screens stay in the list layout', async ({ page }, info) => {
  test.setTimeout(180_000);
  const data = manifest();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/testler/yeni');
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni test' })).toBeVisible();
    await expect(page.getByText('Kaydetme taslak sürüm oluşturur. Yayın ayrı işlemdir.')).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Sınıflandırma' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Test bilgileri' })).toBeVisible();
    await expect(page.getByLabel('Kazanım kodu')).toBeVisible();
    await expect(page.getByLabel('Zorluk')).toBeVisible();
    await expect(page.getByLabel('Soru kodu')).toBeVisible();
    await expect(page.getByLabel('Metin')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toBeVisible();
    await expect(page.locator('#test-grade')).toHaveValue('1');
    await expect(page.locator('#test-subject')).toHaveValue('');
    await expect(page.locator('.admin-test-editor__facts')).toContainText('1. sınıf');
    await expect(page.locator('.admin-test-editor__facts')).toContainText('Seçilmedi');
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    if (viewport.width >= 1024) {
      await expectColumns(page, false);
    } else {
      await expectStacked(page);
    }
    if (viewport.name === '1440') {
      await page.locator('#test-title').focus();
      expect(await page.locator('#test-title').evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    const save = await page.getByRole('button', { name: 'Taslak kaydet' }).boundingBox();
    expect(save?.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-test-yeni-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testDraftEdit);
    await expect(page.getByRole('heading', { level: 1, name: 'Testi düzenle' })).toBeVisible();
    await expect(page.getByText(longTitle).first()).toBeVisible();
    await expect(page.getByText('Uzun test sorusu').first()).toBeVisible();
    await expect(page.getByText('1 soru seçili')).toBeVisible();
    await expect(page.getByText('formu gönderir')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Yukarı' })).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    if (viewport.width >= 1024) {
      await expectColumns(page, true);
    } else {
      await expectStacked(page);
    }
    const move = await page.getByRole('button', { name: 'Yukarı' }).boundingBox();
    expect(move?.height).toBeGreaterThanOrEqual(44);
    if (viewport.name === '390' || viewport.name === '1440') {
      await page.getByRole('button', { name: 'Teknik bilgiler' }).first().click();
      await page.evaluate(() => window.scrollTo(0, 0));
      const code = page.locator('code').first();
      await expect(code).toBeVisible();
      const box = await code.boundingBox();
      const panel = page.locator('.admin-test-tech__panel').first();
      const panelBox = await panel.boundingBox();
      expect(box && panelBox).toBeTruthy();
      expect(box!.x + box!.width).toBeLessThanOrEqual(panelBox!.x + panelBox!.width + 1);
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-duzenle-${viewport.name}.png`);
  }

  const subjectId = await page.locator('#test-editor input[name="subject_id"]').inputValue();
  const search = `/yonetim/testler/yeni?grade=1&subject_id=${subjectId}&q=Uzun`;
  const empty = `/yonetim/testler/yeni?grade=1&subject_id=${subjectId}&q=bu-filtre-bos-sonuc`;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(search);
    await expect(page.getByText('Uzun test sorusu').first()).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-arama-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(empty);
    await expect(page.getByText('Bu filtrede yayımlanmış soru yok.')).toBeVisible();
    await expect(page.locator('#test-q')).toHaveValue('bu-filtre-bos-sonuc');
    await assertLayout(page);
    await capture(page, info, `admin-test-arama-bos-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(`/yonetim/testler/yeni?grade=1&subject_id=${subjectId}`);
    await page.locator('#test-title').fill('Hatali taslak');
    await page.locator('#test-instructions').fill('Korunan yonerge');
    await page.locator('#test-duration').fill('25');
    await page.getByRole('button', { name: 'Taslak kaydet' }).click();
    await expect(page.getByText('En az bir soru ekleyin.')).toBeVisible();
    await expect(page.locator('#test-title')).toHaveValue('Hatali taslak');
    await expect(page.locator('#test-instructions')).toHaveValue('Korunan yonerge');
    await expect(page.locator('#test-duration')).toHaveValue('25');
    await expect(page.locator('#test-grade')).toHaveValue('1');
    await expect(page.locator('.admin-test-editor__facts')).toContainText('1. sınıf');
    await expect(page).toHaveURL(/\/yonetim\/testler\/yeni$/);
    await assertLayout(page);
    await capture(page, info, `admin-test-hata-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function expectColumns(page: Page, sideShorter: boolean): Promise<void> {
  const main = await page.locator('.admin-test-editor__main').boundingBox();
  const side = await page.locator('.admin-test-editor__side').boundingBox();
  expect(main && side).toBeTruthy();
  expect(main!.x).toBeLessThan(side!.x);
  if (sideShorter) {
    expect(side!.height).toBeLessThan(main!.height);
  }
}

async function expectStacked(page: Page): Promise<void> {
  const main = await page.locator('.admin-test-editor__main').boundingBox();
  const side = await page.locator('.admin-test-editor__side').boundingBox();
  expect(main && side).toBeTruthy();
  expect(side!.y).toBeLessThan(main!.y);
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
