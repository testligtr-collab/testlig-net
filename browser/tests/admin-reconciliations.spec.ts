import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin reconciliation list layout, statuses, long provider, pagination', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const longProvider = data.paths.reconciliationLongProvider as string;
  const listPath = data.paths.reconciliationsList as string;
  const paginationPath = data.paths.reconciliationsPagination as string;
  expect(longProvider).toBe('sandbox_provider_long_code_xx');
  expect(longProvider.length).toBeLessThanOrEqual(32);

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  // Empty list is covered by HTTP when no runs exist. Browser fixtures seed mixed statuses
  // (no filter/tab available to isolate an empty view without wiping shared fixtures).

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(listPath);
    await expect(page.getByRole('heading', { level: 1, name: 'Uzlaştırma' })).toBeVisible();
    await expect(page.getByText('Önceden çalıştırılan uzlaştırma koşularının salt okunur geçmişi')).toBeVisible();
    await expect(page.getByText('Bu ekrandan koşu başlatılmaz')).toBeVisible();
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Uzlaştırma', exact: true })).toBeVisible();
    }
    await expect(page.getByRole('heading', { level: 2, name: 'Uzlaştırma koşuları' })).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Tamamlandı' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Tutarsızlıklarla tamamlandı' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Başarısız' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Devam ediyor' }).first()).toBeVisible();
    await expect(page.locator('.admin-reconciliation__environment--sandbox:visible').first()).toHaveText('Test (sandbox)');
    await expect(page.locator('.admin-reconciliation__environment--production:visible').first()).toHaveText('Canlı');
    await expect(page.getByText(longProvider).filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText('safe_snapshot_hash')).toHaveCount(0);
    await expect(page.getByText('provider_payment_reference')).toHaveCount(0);
    await expect(page.getByText('sk_live')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /koşu|uzlaştır|reconcile|yeniden/i })).toHaveCount(0);

    const row = page.locator('tr', { hasText: longProvider }).first();
    await expect(row).toBeVisible();
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      const statusBox = await row.locator('.admin-reconciliation__status').boundingBox();
      const actionBox = await row.locator('.admin-reconciliation__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      expect(boxesOverlap(statusBox!, actionBox!)).toBeFalsy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const open = row.locator('.admin-reconciliation__open');
      await expect(open).toBeVisible();
      const openBox = await open.boundingBox();
      expect(openBox).toBeTruthy();
      expect(openBox!.height).toBeGreaterThanOrEqual(44);
    }

    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(listPath);
    const row = page.locator('tr', { hasText: longProvider }).first();
    const provider = row.locator('.admin-reconciliation__provider:visible').first();
    await expect(provider).toBeVisible();
    await expect(provider).toHaveText(longProvider);
    const metrics = await provider.evaluate((el) => {
      const box = el.getBoundingClientRect();
      const wrap = el.closest('.admin-table-wrap, .admin-reconciliation-list__card, main') as HTMLElement | null;
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
    // Distinct from the top-of-list mixed shot: center the domain-max provider row after sizing.
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.setViewportSize({ width: viewport.width, height: Math.max(900, Math.min(height, 2400)) });
    await row.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    const file = path.join('artifacts', 'screenshots', `admin-uzlastirma-uzun-${viewport.name}.png`);
    await page.screenshot({ path: file, fullPage: false });
    await info.attach(`admin-uzlastirma-uzun-${viewport.name}.png`, { path: file, contentType: 'image/png' });
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(paginationPath);
    await expect(page.locator('.admin-pagination')).toContainText('2/sayfa');
    await expect(page.locator('.admin-pagination a', { hasText: 'Sonraki' })).toBeVisible();
    const next = page.locator('.admin-pagination a', { hasText: 'Sonraki' });
    await expect(next).toHaveAttribute('href', /page_size=2/);
    if (viewport.width < 1024) {
      const box = await next.boundingBox();
      expect(box).toBeTruthy();
      expect(box!.height).toBeGreaterThanOrEqual(40);
    }
    await next.click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page).toHaveURL(/page_size=2/);
    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-sayfa-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(listPath);
  const detail = page.locator('.admin-reconciliation__action a').first();
  await detail.focus();
  expect(await detail.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await assertClean(page);
});

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
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
