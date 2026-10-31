import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const detailTitle = 'Uzun detay testi basligi soru listesi uzarken sag kartlar kendi yuksekliginde kalir ve teknik kod satir kirarak sarilir.';

test('admin test detail keeps publication, access, and catalog apart', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testPublishedDetail);
    await expect(page.getByRole('heading', { level: 1, name: detailTitle })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Önizleme', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sonuçlar', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Düzenle', exact: true })).toHaveCount(0);
    await expect(page.getByText('Yeni sürüm')).toHaveCount(0);
    await expect(page.locator('.admin-test-detail__count')).toHaveText('10');
    await expect(page.locator('.admin-test-detail__points')).toHaveText('10');
    await expect(page.locator('.admin-test-detail__duration')).toHaveText('15 dk');
    await expect(page.locator('.admin-test-detail__status')).toHaveText('Yayında');
    await expect(page.locator('.admin-test-detail__policy')).toHaveText('Politika tanımlanmamış');
    await expect(page.locator('.admin-test-detail__placement-status')).toHaveText('Taslak');
    await expect(page.getByText('Görsel tasarım örneği')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Test ve sınavlar', exact: true })).toHaveAttribute('aria-current', 'page');
      const pool = page.getByRole('link', { name: 'Soru havuzu', exact: true });
      if (await pool.count()) {
        await expect(pool).not.toHaveAttribute('aria-current', 'page');
      }
      await expectColumns(page);
    } else {
      await expectStacked(page);
    }
    const preview = await page.getByRole('link', { name: 'Önizleme', exact: true }).boundingBox();
    expect(preview?.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-test-detay-yayin-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testDraft);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Uzun platform testi basligi');
    await expect(page.locator('.admin-test-detail__status')).toHaveText('Taslak');
    await expect(page.getByRole('link', { name: 'Düzenle', exact: true })).toBeVisible();
    await expect(page.getByText('Yönerge yok.')).toBeVisible();
    await expect(page.getByText('Uzun test sorusu')).toBeVisible();
    await expect(page.getByRole('button', { name: 'İşlemler' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sonuçlar', exact: true })).toBeVisible();
    if (viewport.width >= 1024) {
      await expectBeside(page);
    } else {
      await expectStacked(page);
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-detay-taslak-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testReview);
    await page.getByRole('button', { name: 'İşlemler' }).click();
    await expect(page.getByRole('button', { name: 'Yayınla', exact: true })).toBeVisible();
    await expect(page.locator('form[action$="/yayinla"] textarea[name="operator_note"]')).toBeVisible();
    await expect(page.locator('form[action$="/yayinla"] input[name="expected_revision"]')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Arşivle', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Düzenle', exact: true })).toHaveCount(0);
    if (viewport.name === '1440') {
      await page.getByRole('button', { name: 'Yayınla', exact: true }).focus();
      expect(await page.getByRole('button', { name: 'Yayınla', exact: true }).evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    const publish = await page.getByRole('button', { name: 'Yayınla', exact: true }).boundingBox();
    expect(publish?.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-test-detay-inceleme-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testPublishedDetail);
    await page.getByRole('button', { name: 'Teknik bilgiler' }).click();
    await page.evaluate(() => window.scrollTo(0, 0));
    const code = page.locator('.admin-test-detail .admin-test-tech__panel code').first();
    await expect(code).toBeVisible();
    const box = await code.boundingBox();
    const panel = page.locator('.admin-test-detail .admin-test-tech__panel');
    const panelBox = await panel.boundingBox();
    expect(box && panelBox).toBeTruthy();
    expect(box!.x + box!.width).toBeLessThanOrEqual(panelBox!.x + panelBox!.width + 1);
    await assertLayout(page);
    await capture(page, info, `admin-test-detay-teknik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testPublishedDetail);
    await page.getByRole('button', { name: 'Erişimi yönet' }).click();
    await page.getByRole('button', { name: 'Yerleşim işlemleri' }).click();
    await expect(page.getByRole('checkbox', { name: 'Bu test ücretsiz erişime açılacak' })).toBeVisible();
    await expect(page.locator('#placement-create-form')).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Onaylıyorum' })).toBeVisible();
    await expect(page.locator('.admin-test-detail__policy')).toHaveText('Politika tanımlanmamış');
    await expect(page.locator('.admin-test-detail__placement-status')).toHaveText('Taslak');
    if (viewport.name === '390') {
      const save = await page.getByRole('button', { name: 'Politikayı kaydet' }).boundingBox();
      expect(save?.height).toBeGreaterThanOrEqual(44);
    }
    await page.evaluate(() => window.scrollTo(0, 0));
    await assertLayout(page);
    await capture(page, info, `admin-test-detay-islem-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function expectColumns(page: Page): Promise<void> {
  const main = await page.locator('.admin-test-detail__main').boundingBox();
  const side = await page.locator('.admin-test-detail__side').boundingBox();
  expect(main && side).toBeTruthy();
  expect(main!.x).toBeLessThan(side!.x);
  const cards = page.locator('.admin-test-detail__side > .admin-test-detail__card');
  expect(await cards.count()).toBe(3);
  let sum = 0;
  for (let index = 0; index < 3; index += 1) {
    const card = await cards.nth(index).boundingBox();
    expect(card).toBeTruthy();
    sum += card!.height;
  }
  expect(side!.height).toBeGreaterThanOrEqual(sum - 1);
  expect(side!.height).toBeLessThanOrEqual(sum + 48);
}

async function expectBeside(page: Page): Promise<void> {
  const main = await page.locator('.admin-test-detail__main').boundingBox();
  const side = await page.locator('.admin-test-detail__side').boundingBox();
  expect(main && side).toBeTruthy();
  expect(main!.x).toBeLessThan(side!.x);
}

async function expectStacked(page: Page): Promise<void> {
  const main = await page.locator('.admin-test-detail__main').boundingBox();
  const side = await page.locator('.admin-test-detail__side').boundingBox();
  expect(main && side).toBeTruthy();
  expect(main!.y).toBeLessThan(side!.y);
  expect(Math.abs(main!.x - side!.x)).toBeLessThan(8);
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
