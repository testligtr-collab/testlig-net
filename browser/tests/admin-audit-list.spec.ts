import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin audit list layout, outcomes, long metadata, pagination', async ({ page }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const listPath = data.paths.auditList as string;
  const paginationPath = data.paths.auditPagination as string;
  const longReason = data.paths.auditLongReason as string;
  const longActionLabel = data.paths.auditLongActionLabel as string;
  expect(longReason.length).toBeGreaterThan(40);
  expect(longActionLabel).toContain('uzlaştırma');

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  // Empty list is covered by HTTP after clearing events. Browser fixtures share a seeded DB
  // (login + other screens write audits), so empty cannot be isolated here without wiping peers.

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(listPath);
    await expect(page.getByRole('heading', { level: 1, name: 'Güvenlik denetim olayları' })).toBeVisible();
    await expect(page.getByText('Sistem güvenlik olaylarının salt okunur kaydı')).toBeVisible();
    await expect(page.getByText('Bu ekrandan olay oluşturulmaz veya değiştirilmez')).toBeVisible();
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Denetim', exact: true })).toBeVisible();
    }
    await expect(page.getByRole('heading', { level: 2, name: 'Denetim olayları' })).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Başarılı' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Başarısız' }).first()).toBeVisible();
    await expect(page.getByText('Giriş başarılı').filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText('Ödeme başarısız').filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText('CLI').filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText(longActionLabel).filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText(longReason).filter({ visible: true }).first()).toBeVisible();
    await expect(page.locator('form[action="/yonetim/calisma-kimligi"]')).toHaveCount(0);
    await expect(page.getByText('audit_list_canary_token')).toHaveCount(0);
    await expect(page.getByText('ip_hash')).toHaveCount(0);
    await expect(page.getByText('login_succeeded')).toHaveCount(0);

    const row = page.locator('tr', { hasText: longReason }).first();
    await expect(row).toBeVisible();
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      const actionBox = await row.locator('.admin-audit__action-cell').boundingBox();
      const outcomeBox = await row.locator('.admin-audit__outcome').boundingBox();
      expect(actionBox && outcomeBox).toBeTruthy();
      expect(boxesOverlap(actionBox!, outcomeBox!)).toBeFalsy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      await expect(row.locator('.admin-audit__mobile')).toBeVisible();
    }

    await assertLayout(page);
    await capture(page, info, `admin-denetim-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(listPath);
    const row = page.locator('tr', { hasText: longReason }).first();
    const meta = row.locator('.admin-audit__meta:visible').first();
    await expect(meta).toBeVisible();
    await expect(meta).toContainText(longReason);
    const metrics = await meta.evaluate((el) => {
      const box = el.getBoundingClientRect();
      const wrap = el.closest('.admin-table-wrap, .admin-audit-list__card, main') as HTMLElement | null;
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
    await page.setViewportSize(viewport);
    await row.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    const file = path.join('artifacts', 'screenshots', `admin-denetim-uzun-${viewport.name}.png`);
    await page.screenshot({ path: file, fullPage: false });
    await info.attach(`admin-denetim-uzun-${viewport.name}.png`, { path: file, contentType: 'image/png' });
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
    await next.focus();
    const focusStyle = await next.evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(focusStyle).not.toBe('none');
    await next.click();
    await expect(page).toHaveURL(/page=2/);
    await expect(page).toHaveURL(/page_size=2/);
    await assertLayout(page);
    await capture(page, info, `admin-denetim-sayfa-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(listPath);
  await assertClean(page);
  // Do not submit the optional web-runtime identity form.
});

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: true });
  await info.attach(name, { path: file, contentType: 'image/png' });
}

function boxesOverlap(
  a: { x: number; y: number; width: number; height: number },
  b: { x: number; y: number; width: number; height: number },
): boolean {
  return !(a.x + a.width <= b.x || b.x + b.width <= a.x || a.y + a.height <= b.y || b.y + b.height <= a.y);
}
