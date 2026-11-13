import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin reconciliation detail layouts, empty copy, mixed rows, long codes, pagination', async ({ page }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  const completedEmpty = data.paths.reconciliationDetailCompletedEmpty as string;
  const mixed = data.paths.reconciliationDetailMixed as string;
  const failedEmpty = data.paths.reconciliationDetailFailedEmpty as string;
  const runningEmpty = data.paths.reconciliationDetailRunningEmpty as string;
  const longPath = data.paths.reconciliationDetailLong as string;
  const longProvider = data.paths.reconciliationDetailLongProvider as string;
  const longReason = data.paths.reconciliationDetailLongReason as string;
  const pagination = data.paths.reconciliationDetailPagination as string;

  expect(longProvider).toBe('sandbox_provider_long_code_xx');
  expect(longProvider.length).toBeLessThanOrEqual(32);
  expect(longReason).toBe('ops_manual_reconciliation_long_reason_code_x');
  expect(longReason.length).toBeLessThanOrEqual(64);

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(completedEmpty);
    await expect(page.getByRole('heading', { level: 1, name: 'Uzlaştırma detayı' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Uzlaştırma listesine dön' })).toBeVisible();
    await expect(page.getByText('Bu ekran koşu başlatmaz')).toBeVisible();
    await expect(page.locator('.admin-reconciliation-detail__status', { hasText: 'Tamamlandı' })).toBeVisible();
    await expect(page.getByText('Bu koşuda henüz fark kaydı bulunmuyor')).toBeVisible();
    await expect(page.getByText('koşu durumunu dikkate alın')).toBeVisible();
    await expect(page.getByText('Eşleşen satırlar bu tabloda gösterilmez')).toBeVisible();
    await expect(page.locator('.admin-reconciliation-detail-table')).toHaveCount(0);
    await expect(page.getByRole('button', { name: /koşu|uzlaştır|reconcile|düzelt/i })).toHaveCount(0);
    await assertBackTarget(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-detay-bos-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(mixed);
    await expect(page.locator('.admin-reconciliation-detail__status', { hasText: 'Tutarsızlıklarla tamamlandı' })).toBeVisible();
    await expect(page.getByText('Yerel geride').first()).toBeVisible();
    await expect(page.getByText('Sağlayıcı geride').first()).toBeVisible();
    await expect(page.getByText('Tutar uyuşmazlığı').first()).toBeVisible();
    await expect(page.getByText('Başarısız').first()).toBeVisible();
    await expect(page.getByText('Webhook yeniden kuyruğa alındı').first()).toBeVisible();
    await expect(page.getByText('Manuel inceleme gerekli').first()).toBeVisible();
    if (viewport.width >= 1024) {
      await expect.poll(async () => page.locator('.admin-reconciliation-detail-table td').first().evaluate((el) => getComputedStyle(el).display)).toBe('table-cell');
    } else {
      await expect.poll(async () => page.locator('.admin-reconciliation-detail-table tr').first().evaluate((el) => getComputedStyle(el).display)).toBe('block');
      await expect(page.locator('.admin-reconciliation-detail__mobile').first()).toBeVisible();
    }
    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-detay-karma-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(failedEmpty);
    await expect(page.locator('.admin-reconciliation-detail__status', { hasText: 'Başarısız' })).toBeVisible();
    await expect(page.getByText('Bu koşuda henüz fark kaydı bulunmuyor')).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-detay-basarisiz-${viewport.name}.png`);

    await page.goto(runningEmpty);
    await expect(page.locator('.admin-reconciliation-detail__status', { hasText: 'Devam ediyor' })).toBeVisible();
    await expect(page.getByText('koşu durumunu dikkate alın')).toBeVisible();
    const bitis = page.locator('dt', { hasText: 'Bitiş' }).locator('xpath=following-sibling::dd[1]');
    await expect(bitis).toHaveText('—');
    await assertLayout(page);
    await capture(page, info, `admin-uzlastirma-detay-devam-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(longPath);
    await expect(page.locator('.admin-reconciliation-detail__status', { hasText: 'Tutarsızlıklarla tamamlandı' })).toBeVisible();
    await expect(page.getByText('Zamanlanmış')).toBeVisible();
    await expect(page.locator('.admin-reconciliation-detail__environment--production')).toHaveText('Canlı');
    const provider = page.locator('.admin-reconciliation-detail__provider');
    const reason = page.locator('.admin-reconciliation-detail__reason');
    await expect(provider).toHaveText(longProvider);
    await expect(reason).toHaveText(longReason);
    const metrics = await provider.evaluate((el) => {
      const box = el.getBoundingClientRect();
      return {
        right: box.right - document.documentElement.clientWidth,
        pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    expect(metrics.right).toBeLessThanOrEqual(1);
    expect(metrics.pageOverflow).toBeLessThanOrEqual(1);
    await assertLayout(page);
    await reason.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    const file = path.join('artifacts', 'screenshots', `admin-uzlastirma-detay-uzun-${viewport.name}.png`);
    await page.screenshot({ path: file, fullPage: false });
    await info.attach(`admin-uzlastirma-detay-uzun-${viewport.name}.png`, { path: file, contentType: 'image/png' });
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(pagination);
    await expect(page.locator('.admin-pagination')).toContainText('2/sayfa');
    const next = page.locator('.admin-pagination a', { hasText: 'Sonraki' });
    await expect(next).toBeVisible();
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
    await capture(page, info, `admin-uzlastirma-detay-sayfa-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(mixed);
  await assertClean(page);
});

async function assertBackTarget(page: Page, width: number): Promise<void> {
  const back = page.locator('a.admin-reconciliation-detail__back');
  await expect(back).toBeVisible();
  await expect(back).toHaveAttribute('href', '/yonetim/uzlastirma');
  const box = await back.boundingBox();
  expect(box).toBeTruthy();
  expect(box!.height).toBeGreaterThanOrEqual(width < 1024 ? 44 : 40);
  await back.focus();
  await expect(back).toBeFocused();
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: true });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
