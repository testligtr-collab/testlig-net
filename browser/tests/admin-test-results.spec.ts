import path from 'node:path';
import { expect, test, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin test results keep the open layout and the completed-run gate', async ({ page }, info) => {
  test.setTimeout(360_000);
  const data = manifest();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResults);
    await expect(page.getByRole('heading', { level: 1, name: 'Sentetik sonuc testi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Test detayına dön' })).toBeVisible();
    await expect(page.getByText(data.paths.testResultStudent)).toBeVisible();
    await expect(page.getByText('Tamamlandı', { exact: true })).toBeVisible();
    await expect(page.getByText('Devam ediyor', { exact: true })).toBeVisible();
    await expect(page.getByText('İptal', { exact: true })).toBeVisible();
    await expect(page.getByText('henüz sonuç yok')).toHaveCount(0);
    await expect(page.getByText('Doğru cevap')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await expect(page.getByText('browser-result-done@example.test')).toHaveCount(0);
    const links = page.getByRole('link', { name: 'İncele' });
    await expect(links).toHaveCount(3);
    const open = await links.first().boundingBox();
    expect(open?.height).toBeGreaterThanOrEqual(44);
    if (viewport.width >= 1024) {
      await expect(page.getByRole('columnheader', { name: 'Öğrenci' })).toBeVisible();
      await expect(page.locator('.admin-result-field__label').first()).toBeHidden();
    } else {
      await expect(page.getByRole('columnheader')).toHaveCount(0);
      await expect(page.locator('.admin-result-field__label').first()).toBeVisible();
      const row = await page.locator('.admin-test-results__table tbody tr').first().boundingBox();
      expect(row).toBeTruthy();
      expect(row!.width).toBeLessThanOrEqual(viewport.width);
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-liste-${viewport.name}.png`);
    await capture(page, info, `admin-test-sonuc-karisik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultsEmpty);
    await expect(page.getByText('Bu test için henüz sonuç yok.')).toBeVisible();
    await expect(page.getByText('Aramanla eşleşen sonuç yok.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-bos-${viewport.name}.png`);

    await page.goto(`${data.paths.testResults}?q=Yokboyle`);
    await expect(page.getByText('Aramanla eşleşen sonuç yok.')).toBeVisible();
    await expect(page.getByText('Bu test için henüz sonuç yok.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-arama-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultCompleted);
    await expect(page.getByRole('heading', { level: 1, name: 'Sentetik sonuc testi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sonuç listesine dön' })).toBeVisible();
    await expect(page.getByText('Kalın kök')).toBeVisible();
    await expect(page.getByText('Liste maddesi bir')).toBeVisible();
    await expect(page.locator('.q-frac')).toBeVisible();
    await expect(page.getByText('Öğrencinin cevabı')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: 'Çözüm' })).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await expect(page.getByText('opt_')).toHaveCount(0);
    const column = await page.locator('.admin-test-results--detail').boundingBox();
    expect(column).toBeTruthy();
    if (viewport.width >= 1024) {
      expect(column!.width).toBeLessThanOrEqual(780);
    }
    const math = await page.locator('.q-math').first().boundingBox();
    const card = await page.locator('.admin-test-results__question').first().boundingBox();
    expect(math && card).toBeTruthy();
    expect(math!.x).toBeGreaterThanOrEqual(card!.x - 1);
    expect(math!.x + math!.width).toBeLessThanOrEqual(card!.x + card!.width + 1);
    const levels = await page.locator('main h1, main h2, main h3').evaluateAll((nodes) => nodes.map((node) => Number(node.tagName.slice(1))));
    expect(levels[0]).toBe(1);
    for (let index = 1; index < levels.length; index += 1) {
      expect(levels[index]).toBeLessThanOrEqual(levels[index - 1] + 1);
    }
    if (viewport.name === '1440') {
      await page.getByRole('link', { name: 'Sonuç listesine dön' }).focus();
      const outline = await page.getByRole('link', { name: 'Sonuç listesine dön' }).evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(outline).not.toBe('none');
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-detay-${viewport.name}.png`);
    await capture(page, info, `admin-test-sonuc-detay-bicim-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultOpen);
    await expect(page.getByText('Bu deneme henüz tamamlanmadı.')).toBeVisible();
    await expect(page.getByText('Devam ediyor')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toHaveCount(0);
    await expect(page.getByText('Çözüm')).toHaveCount(0);
    await expect(page.getByText('Kalın kök')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-detay-acik-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 6400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
