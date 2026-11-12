import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin webhook list layout, tabs, empty rejected, long event type, pagination', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const longLabel = data.paths.webhookLongEventTypeLabel as string;
  expect(longLabel).toBe('İade talebi');

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  // due empty is covered by HTTP when no inbox rows exist. Browser fixtures fill due/dead_letter
  // and detail statuses; retry_pending stays empty and exercises the same empty_state component.
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/webhook?tab=retry_pending');
    await expect(page.getByRole('heading', { level: 1, name: 'Webhook' })).toBeVisible();
    await expect(page.getByText('Kuyruk boş')).toBeVisible();
    await expect(page.getByText('Bu sekmede gösterilecek webhook olayı yok.')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Yeniden deneme bekliyor' })).toHaveAttribute('aria-current', 'page');
    await assertTabsReachable(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-webhook-bos-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/webhook');
    await expect(page.getByRole('heading', { level: 1, name: 'Webhook' })).toBeVisible();
    await expect(page.getByText('Ödeme webhook kuyruğunu salt okunur izleyin')).toBeVisible();
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Webhook', exact: true })).toBeVisible();
    }
    await expect(page.getByRole('heading', { level: 2, name: 'Webhook listesi' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'İşlenecek' })).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.admin-status:visible', { hasText: 'Alındı' }).first()).toBeVisible();
    await expect(page.locator('.admin-webhook__event-type:visible', { hasText: longLabel }).first()).toBeVisible();
    await expect(page.locator('.admin-webhook__environment--sandbox:visible').first()).toHaveText('Test (sandbox)');
    await expect(page.getByText('payload_hash')).toHaveCount(0);
    await expect(page.getByText('signature_fingerprint')).toHaveCount(0);
    await expect(page.getByText('provider_event_reference')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /yeniden|iade|kuyru/i })).toHaveCount(0);

    const row = page.locator('tr', { hasText: longLabel }).first();
    await expect(row).toBeVisible();
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      const statusBox = await row.locator('.admin-webhook__status').boundingBox();
      const actionBox = await row.locator('.admin-webhook__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      expect(boxesOverlap(statusBox!, actionBox!)).toBeFalsy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const open = row.locator('.admin-webhook__open');
      await expect(open).toBeVisible();
      const openBox = await open.boundingBox();
      expect(openBox).toBeTruthy();
      expect(openBox!.height).toBeGreaterThanOrEqual(44);
    }

    await assertTabsReachable(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-webhook-due-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/webhook?tab=dead_letter');
    await expect(page.getByRole('tab', { name: 'Ölü mektup' })).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('.admin-status:visible', { hasText: 'Ölü mektup' }).first()).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Webhook listesi' })).toBeVisible();
    await expect(page.getByText('payload_hash')).toHaveCount(0);
    await assertTabsReachable(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-webhook-olu-mektup-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/webhook');
    const type = page.locator('.admin-webhook__event-type:visible', { hasText: longLabel }).first();
    await expect(type).toBeVisible();
    await expect(type).toHaveText(longLabel);
    const metrics = await type.evaluate((el) => {
      const box = el.getBoundingClientRect();
      const wrap = el.closest('.admin-table-wrap, .admin-webhook-list, main') as HTMLElement | null;
      return {
        overflow: wrap ? el.scrollWidth - wrap.clientWidth : 0,
        right: box.right - document.documentElement.clientWidth,
        pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    expect(metrics.overflow).toBeLessThanOrEqual(1);
    expect(metrics.right).toBeLessThanOrEqual(1);
    expect(metrics.pageOverflow).toBeLessThanOrEqual(1);
    await assertLayout(page);
    await capture(page, info, `admin-webhook-uzun-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/webhook?tab=due&page_size=2&page=1');
    await expect(page.locator('.admin-pagination')).toContainText('2/sayfa');
    await expect(page.locator('.admin-pagination a', { hasText: 'Sonraki' })).toBeVisible();
    const next = page.locator('.admin-pagination a', { hasText: 'Sonraki' });
    await expect(next).toHaveAttribute('href', /tab=due/);
    await expect(next).toHaveAttribute('href', /page_size=2/);
    await next.click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page).toHaveURL(/page_size=2/);
    await expect(page).toHaveURL(/tab=due/);
    await expect(page.getByRole('tab', { name: 'İşlenecek' })).toHaveAttribute('aria-current', 'page');
    await assertLayout(page);
    await capture(page, info, `admin-webhook-sayfa-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/webhook');
  const detail = page.locator('.admin-webhook__action a').first();
  await detail.focus();
  expect(await detail.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  const activeTab = page.getByRole('tab', { name: 'İşlenecek' });
  await activeTab.focus();
  expect(await activeTab.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await assertClean(page);
});

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

async function assertTabsReachable(page: Page, width: number): Promise<void> {
  const tabs = page.locator('.admin-webhook-tabs .admin-tab');
  await expect(tabs.first()).toBeVisible();
  const count = await tabs.count();
  expect(count).toBeGreaterThanOrEqual(7);
  if (width < 1024) {
    const box = await tabs.nth(count - 1).boundingBox();
    expect(box).toBeTruthy();
    expect(box!.height).toBeGreaterThanOrEqual(40);
  }
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
