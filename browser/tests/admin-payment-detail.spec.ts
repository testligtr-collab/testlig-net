import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin payment detail layouts for domain statuses', async ({ page }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  const initiated = data.paths.paymentDetailInitiated as string;
  const authorized = data.paths.paymentDetailAuthorized as string;
  const captured = data.paths.paymentDetailCaptured as string;
  const failed = data.paths.paymentDetailFailed as string;
  const longFailure = data.paths.paymentDetailLongFailure as string;
  const liveAuthorized = data.paths.paymentDetailLiveAuthorized as string;
  const longFailureCode = data.paths.paymentLongFailureCode as string;
  const longReference = data.paths.paymentLongReference as string;

  expect(longFailureCode).toBe('provider_timeout_insufficient_funds_retry_later');
  expect(longReference).toMatch(/^ORD-[0-9A-F]{24}$/);

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  const scenarios = [
    { key: 'baslatildi', path: initiated, status: 'Başlatıldı', env: 'Test (sandbox)', failure: '—', expectAuthDash: true, expectCaptureDash: true },
    { key: 'yetkilendirildi', path: authorized, status: 'Yetkilendirildi', env: 'Test (sandbox)', failure: '—', expectAuthDash: false, expectCaptureDash: true },
    { key: 'canli', path: liveAuthorized, status: 'Yetkilendirildi', env: 'Canlı', failure: '—', expectAuthDash: false, expectCaptureDash: true },
    { key: 'tahsil', path: captured, status: 'Tahsil edildi', env: 'Test (sandbox)', failure: '—', expectAuthDash: false, expectCaptureDash: false },
    { key: 'basarisiz', path: failed, status: 'Başarısız', env: 'Test (sandbox)', failure: 'card_declined', expectAuthDash: true, expectCaptureDash: true },
    { key: 'uzun-hata', path: longFailure, status: 'Başarısız', env: 'Test (sandbox)', failure: longFailureCode, expectAuthDash: true, expectCaptureDash: true },
  ] as const;

  for (const scenario of scenarios) {
    for (const viewport of VIEWPORTS) {
      await page.setViewportSize(viewport);
      await page.goto(scenario.path);
      await expect(page.getByRole('heading', { level: 1, name: 'Ödeme detayı' })).toBeVisible();
      const back = page.locator('a.admin-payment-detail__back');
      await expect(back).toBeVisible();
      await expect(back).toHaveAttribute('href', '/yonetim/odemeler');
      await expect(back).toHaveText('Ödemeler listesine dön');
      if (viewport.width < 1024) {
        const backBox = await back.boundingBox();
        expect(backBox).toBeTruthy();
        expect(backBox!.height).toBeGreaterThanOrEqual(44);
      }
      await expect(page.locator('.admin-payment-detail__status')).toContainText(scenario.status);
      await expect(page.locator('.admin-payment__environment:visible').first()).toHaveText(scenario.env);
      await expect(page.locator('.admin-payment__amount:visible').first()).toContainText('₺');
      await expect(page.locator('.admin-payment__reference:visible').first()).toBeVisible();
      await expect(page.locator('.admin-payment-detail__failure')).toHaveText(scenario.failure);

      const authText = await page.locator('dt', { hasText: 'Yetkilendirme' }).locator('xpath=following-sibling::dd[1]').innerText();
      const captureText = await page.locator('dt', { hasText: 'Tahsil' }).locator('xpath=following-sibling::dd[1]').innerText();
      if (scenario.expectAuthDash) {
        expect(authText.trim()).toBe('—');
      } else {
        expect(authText.trim()).not.toBe('—');
      }
      if (scenario.expectCaptureDash) {
        expect(captureText.trim()).toBe('—');
      } else {
        expect(captureText.trim()).not.toBe('—');
      }

      await expect(page.getByRole('button', { name: /iade|tahsil|yeniden/i })).toHaveCount(0);
      await expect(page.getByText('sk_live')).toHaveCount(0);
      await expect(page.getByText('br_pay_auth_ref')).toHaveCount(0);
      await expect(page.getByText('br_pay_cap_ref')).toHaveCount(0);

      if (viewport.width >= 1024) {
        const cards = page.locator('.admin-payment-detail__card');
        await expect(cards).toHaveCount(4);
        const summary = await cards.nth(0).boundingBox();
        const order = await cards.nth(1).boundingBox();
        expect(summary && order).toBeTruthy();
        expect(boxesOverlap(summary!, order!)).toBeFalsy();
      }

      await assertFailureFits(page, scenario.failure);
      await assertLayout(page);
      await capture(page, info, `admin-odeme-detay-${scenario.key}-${viewport.name}.png`);
    }
  }

  // Long domain ORD reference on initiated detail (same allowlist; no fabricated longer ref).
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(initiated);
  await expect(page.getByRole('heading', { level: 1, name: 'Ödeme detayı' })).toBeVisible();
  const back = page.locator('a.admin-payment-detail__back');
  await back.focus();
  expect(await back.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await assertClean(page);
});

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

async function assertFailureFits(page: Page, failure: string): Promise<void> {
  if (failure === '—') {
    return;
  }
  const metrics = await page.evaluate((code) => {
    const el = document.querySelector('.admin-payment-detail__failure') as HTMLElement | null;
    if (!el || !(el.textContent ?? '').includes(code)) {
      return null;
    }
    const card = el.closest('.admin-payment-detail__card') as HTMLElement | null;
    const box = el.getBoundingClientRect();
    const cardBox = card?.getBoundingClientRect();
    const viewport = document.documentElement.clientWidth;

    return {
      text: (el.textContent ?? '').trim(),
      elVsCard: card ? el.scrollWidth - card.clientWidth : 0,
      elRightVsViewport: box.right - viewport,
      cardRightVsViewport: cardBox ? cardBox.right - viewport : 0,
      pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  }, failure);

  expect(metrics, 'failure code node missing').not.toBeNull();
  expect(metrics!.text).toBe(failure);
  expect(metrics!.elVsCard, 'failure scrollWidth vs card').toBeLessThanOrEqual(1);
  expect(metrics!.elRightVsViewport, 'failure right vs viewport').toBeLessThanOrEqual(1);
  expect(metrics!.cardRightVsViewport, 'card right vs viewport').toBeLessThanOrEqual(1);
  expect(metrics!.pageOverflow, 'document overflow').toBeLessThanOrEqual(1);
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
