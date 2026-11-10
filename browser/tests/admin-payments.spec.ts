import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin payments list layout, filters, empty result, long reference, pagination', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const longReference = data.paths.paymentLongReference as string;
  const failedReference = data.paths.paymentFailedReference as string;

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/odemeler');
    await expect(page.getByRole('heading', { level: 1, name: 'Ödemeler' })).toBeVisible();
    await expect(page.getByText('Ödeme denemelerini salt okunur izleyin')).toBeVisible();
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Ödemeler', exact: true })).toBeVisible();
    }
    await expect(page.getByRole('heading', { level: 2, name: 'Ödeme listesi' })).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Başlatıldı' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Başarısız' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Yetkilendirildi' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Tahsil edildi' }).first()).toBeVisible();
    await expect(page.locator('.admin-payment__environment--sandbox:visible').first()).toBeVisible();
    await expect(page.locator('.admin-payment__environment--sandbox:visible').first()).toHaveText('Test (sandbox)');
    await expect(page.locator('.admin-payment__environment--production:visible').first()).toBeVisible();
    await expect(page.locator('.admin-payment__environment--production:visible').first()).toHaveText('Canlı');
    await expect(page.getByText(longReference).filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText('sk_live')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /iade|tahsil|yeniden/i })).toHaveCount(0);

    const row = page.locator('tr', { hasText: longReference });
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      const statusBox = await row.locator('.admin-payment__status').boundingBox();
      const actionBox = await row.locator('.admin-payment__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      expect(boxesOverlap(statusBox!, actionBox!)).toBeFalsy();
      expect(await oneLine(row.locator('.admin-payment__status .admin-status'))).toBeTruthy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const open = row.locator('.admin-payment__open');
      await expect(open).toBeVisible();
      const openBox = await open.boundingBox();
      expect(openBox).toBeTruthy();
      expect(openBox!.height).toBeGreaterThanOrEqual(44);
    }

    await assertLayout(page);
    await capture(page, info, `admin-odemeler-${viewport.name}.png`);
    await capture(page, info, `admin-odemeler-uzun-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(`/yonetim/odemeler?status=failed&order_public_reference=${encodeURIComponent(failedReference)}`);
    await expect(page.getByText('Başarısız').first()).toBeVisible();
    await expect(page.getByText(failedReference).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Temizle' })).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-odemeler-filtre-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/odemeler?status=initiated&order_public_reference=ORD-DOESNOTEXIST000000000000');
    await expect(page.getByText('Sonuç yok')).toBeVisible();
    await expect(page.getByText('Bu filtrelere uyan ödeme denemesi yok.')).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-odemeler-bos-sonuc-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/odemeler?page_size=2&page=1');
    await expect(page.locator('.admin-pagination')).toContainText('2/sayfa');
    await expect(page.locator('.admin-pagination a', { hasText: 'Sonraki' })).toBeVisible();
    const next = page.locator('.admin-pagination a', { hasText: 'Sonraki' });
    await expect(next).toHaveAttribute('href', /page_size=2/);
    await next.click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page).toHaveURL(/page_size=2/);
    await assertLayout(page);
    await capture(page, info, `admin-odemeler-sayfa-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/odemeler');
  const detail = page.locator('.admin-payment__action a').first();
  await detail.focus();
  expect(await detail.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await assertClean(page);
});

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

async function oneLine(locator: Locator): Promise<boolean> {
  return locator.evaluate((node) => {
    const style = getComputedStyle(node);
    const height = (node as HTMLElement).getBoundingClientRect().height;
    const line = Number.parseFloat(style.lineHeight) || Number.parseFloat(style.fontSize) * 1.2;

    return height <= line * 1.8;
  });
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  await page.evaluate(() => window.scrollTo(0, 0));
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 2400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
