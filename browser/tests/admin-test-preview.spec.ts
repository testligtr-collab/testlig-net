import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const previewTitle = 'Uzun onizleme basligi dar ekranda kartin icinde sarilir ve formulle secenekler kart disina tasmaz.';

test('admin test preview shows the current revision without an answer key', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(`${data.paths.testPublishedDetail}/gorunum`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Test detayına dön' })).toBeVisible();
    await expect(page.getByText('Test önizlemesi')).toBeVisible();
    await expect(page.getByText('15 dk')).toBeVisible();
    await expect(page.getByRole('radio')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Gönder' })).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Test ve sınavlar', exact: true })).toHaveAttribute('aria-current', 'page');
      await expectMetricRow(page);
    } else {
      await expectMetricStack(page);
    }
    const back = await page.getByRole('link', { name: 'Test detayına dön' }).boundingBox();
    expect(back?.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-test-onizleme-yayin-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testPreview);
    await expect(page.getByRole('heading', { level: 1, name: previewTitle })).toBeVisible();
    await expect(page.getByText('Yönerge belirtilmemiş.')).toBeVisible();
    await expect(page.getByText('Süre sınırı yok')).toBeVisible();
    await expect(page.getByText('Kalın kök')).toBeVisible();
    await expect(page.getByText('Liste maddesi bir')).toBeVisible();
    await expect(page.locator('.q-frac')).toBeVisible();
    await expect(page.getByText('Ikinci paragraf tamami')).toBeVisible();
    await expect(page.getByText('Uzunsecenek', { exact: false })).toBeVisible();
    await expect(page.locator('.admin-test-preview__letter').nth(0)).toHaveText('A');
    await expect(page.locator('.admin-test-preview__letter').nth(1)).toHaveText('B');
    await expect(page.getByRole('radio')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    const column = await page.locator('.admin-test-preview').boundingBox();
    expect(column).toBeTruthy();
    if (viewport.width >= 1024) {
      expect(column!.width).toBeLessThanOrEqual(780);
      await expectMetricRow(page);
    } else {
      await expectMetricStack(page);
    }
    const math = await page.locator('.q-math').first().boundingBox();
    const card = await page.locator('.admin-test-preview__question').nth(1).boundingBox();
    expect(math && card).toBeTruthy();
    expect(math!.x).toBeGreaterThanOrEqual(card!.x - 1);
    expect(math!.x + math!.width).toBeLessThanOrEqual(card!.x + card!.width + 1);
    const levels = await page.locator('main h1, main h2, main h3').evaluateAll((nodes) => nodes.map((node) => Number(node.tagName.slice(1))));
    expect(levels[0]).toBe(1);
    for (let index = 1; index < levels.length; index += 1) {
      expect(levels[index]).toBeLessThanOrEqual(levels[index - 1] + 1);
    }
    if (viewport.name === '1440') {
      await page.getByRole('link', { name: 'Test detayına dön' }).focus();
      const outline = await page.getByRole('link', { name: 'Test detayına dön' }).evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(outline).not.toBe('none');
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-onizleme-icerik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testPreview);
    await page.getByRole('button', { name: 'Teknik bilgiler' }).click();
    await expect(page.locator('.admin-test-preview .admin-test-tech__panel code').first()).toBeVisible();
    const summary = await page.getByRole('button', { name: 'Teknik bilgiler' }).boundingBox();
    expect(summary?.height).toBeGreaterThanOrEqual(44);
    await page.evaluate(() => window.scrollTo(0, 0));
    await assertLayout(page);
    await capture(page, info, `admin-test-onizleme-teknik-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function expectMetricRow(page: Page): Promise<void> {
  const first = await page.locator('.admin-test-preview__metric').nth(0).boundingBox();
  const second = await page.locator('.admin-test-preview__metric').nth(1).boundingBox();
  expect(first && second).toBeTruthy();
  expect(first!.x).toBeLessThan(second!.x);
  expect(Math.abs(first!.y - second!.y)).toBeLessThan(8);
}

async function expectMetricStack(page: Page): Promise<void> {
  const first = await page.locator('.admin-test-preview__metric').nth(0).boundingBox();
  const second = await page.locator('.admin-test-preview__metric').nth(1).boundingBox();
  expect(first && second).toBeTruthy();
  expect(first!.y).toBeLessThan(second!.y);
  expect(Math.abs(first!.x - second!.x)).toBeLessThan(8);
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 6400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
