import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const longTitle = 'Uzun platform testi basligi dar ekranda kartta ve masaustunde teknik kodun yaninda sarilir ve tasmadan devam eder.';

test('admin test list, filters, counters, and technical details', async ({ page, browser }, info) => {
  test.setTimeout(180_000);
  const data = manifest();

  const moderatorContext = await browser.newContext();
  const moderator = await moderatorContext.newPage();
  installVideoAbort(moderator);
  await watch(moderator);
  await login(moderator, data.users.moderator);
  await moderator.goto('/yonetim/testler');
  await expect(moderator.getByRole('heading', { level: 1, name: 'Testler' })).toBeVisible();
  await expect(moderator.getByRole('link', { name: 'Yeni test', exact: true })).toHaveCount(0);
  await moderatorContext.close();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/testler');
    await expect(page.getByRole('heading', { level: 1, name: 'Testler' })).toBeVisible();
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Test ve sınavlar', exact: true })).toBeVisible();
    }
    await expect(page.getByRole('link', { name: 'Yeni test', exact: true })).toBeVisible();
    await expect(page.getByText('Sayaçlar filtrelerden bağımsızdır.')).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Test listesi' })).toBeVisible();
    await expect(page.getByText(data.paths.testTitle).first()).toBeVisible();
    await expect(page.getByText(longTitle).first()).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    const row = page.locator('tr', { hasText: longTitle });
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      expect(await oneLine(row.locator('.admin-test__status .admin-status'))).toBeTruthy();
      expect(await oneLine(row.locator('.admin-test__action a'))).toBeTruthy();
      const statusBox = await row.locator('.admin-test__status').boundingBox();
      const actionBox = await row.locator('.admin-test__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      expect(boxesOverlap(statusBox!, actionBox!)).toBeFalsy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const nameBox = await row.locator('.admin-test__name').boundingBox();
      const badgeBox = await row.locator('.admin-test__card-status').boundingBox();
      const actionBox = await row.locator('.admin-test__open').boundingBox();
      expect(nameBox && badgeBox && actionBox).toBeTruthy();
      expect(boxesOverlap(nameBox!, badgeBox!)).toBeFalsy();
      expect(boxesOverlap(nameBox!, actionBox!)).toBeFalsy();
      expect(actionBox!.height).toBeGreaterThanOrEqual(44);
    }
    await assertLayout(page);
    await capture(page, info, `admin-testler-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/testler');
    const row = page.locator('tr', { hasText: longTitle });
    const summary = row.locator('summary');
    if (viewport.name === '1440') {
      await summary.focus();
      expect(await summary.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
    }
    await summary.click();
    await page.evaluate(() => window.scrollTo(0, 0));
    const code = row.locator('code').first();
    await expect(code).toBeVisible();
    const box = await code.boundingBox();
    expect(box).toBeTruthy();
    expect(box!.width).toBeGreaterThan(box!.height);
    expect(box!.width).toBeGreaterThan(40);
    const panel = row.locator('.admin-test-tech__panel');
    const panelBox = await panel.boundingBox();
    const codeRight = box!.x + box!.width;
    const panelRight = panelBox!.x + panelBox!.width;
    expect(codeRight).toBeLessThanOrEqual(panelRight + 1);
    await assertLayout(page);
    await capture(page, info, `admin-testler-teknik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/testler?q=bu-filtre-bos-sonuc');
    await expect(page.getByText('Bu filtreye uyan test yok.')).toBeVisible();
    await expect(page.locator('[data-test-summary="published"]')).toBeVisible();
    await expect(page.locator('[data-test-summary="published"]')).not.toHaveText('0');
    await assertLayout(page);
    await capture(page, info, `admin-testler-bos-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function oneLine(locator: Locator): Promise<boolean> {
  return locator.evaluate((node) => {
    const texts = [...node.childNodes].filter((child) => child.nodeType === Node.TEXT_NODE && (child.textContent ?? '').trim() !== '');
    const target = texts.length > 0 ? texts[texts.length - 1] : node;
    const range = document.createRange();
    range.selectNodeContents(target);
    const rects = [...range.getClientRects()].filter((rect) => rect.width > 0 && rect.height > 0);
    const top = rects[0]?.top ?? 0;

    return rects.length > 0 && rects.every((rect) => Math.abs(rect.top - top) < 4);
  });
}

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width - 1 && a.x + a.width > b.x + 1 && a.y < b.y + b.height - 1 && a.y + a.height > b.y + 1;
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
