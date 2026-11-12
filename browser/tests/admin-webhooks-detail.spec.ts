import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin webhook detail layouts for domain statuses without requeue submit', async ({ page }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  const received = data.paths.webhookDetailReceived as string;
  const deadLetter = data.paths.webhookDetailDeadLetter as string;
  const processed = data.paths.webhookDetailProcessed as string;
  const rejected = data.paths.webhookDetailRejected as string;
  const staleLease = data.paths.webhookDetailStaleLease as string;
  const longFailure = data.paths.webhookDetailLongFailure as string;
  const longFailureCode = data.paths.webhookDetailLongFailureCode as string;
  const paymentAttemptPath = data.paths.webhookDetailPaymentAttemptPath as string;

  expect(longFailureCode).toBe('provider_timeout_insufficient_funds_retry_later');
  expect(paymentAttemptPath).toMatch(/^\/yonetim\/odemeler\/[0-9a-f-]{36}$/i);

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.superadmin);

  const scenarios = [
    {
      key: 'alindi',
      path: received,
      status: 'Alındı',
      eventType: 'Yetkilendirildi',
      failure: '—',
      expectForm: false,
      expectStale: false,
      expectEmptyTiming: true,
      expectPaymentLink: true,
    },
    {
      key: 'olu-mektup',
      path: deadLetter,
      status: 'Ölü mektup',
      eventType: 'Başarısız',
      failure: 'retry_exhausted',
      expectForm: true,
      expectStale: false,
      expectEmptyTiming: true,
      expectPaymentLink: true,
    },
    {
      key: 'islendi',
      path: processed,
      status: 'İşlendi',
      eventType: 'Tahsil edildi',
      failure: '—',
      expectForm: false,
      expectStale: false,
      expectEmptyTiming: true,
      expectPaymentLink: true,
    },
    {
      key: 'reddedildi',
      path: rejected,
      status: 'Reddedildi',
      eventType: 'İptal',
      failure: 'retry_exhausted',
      expectForm: false,
      expectStale: false,
      expectEmptyTiming: true,
      expectPaymentLink: true,
    },
    {
      key: 'stale',
      path: staleLease,
      status: 'İşleniyor',
      eventType: 'Yetkilendirildi',
      failure: '—',
      expectForm: false,
      expectStale: true,
      expectEmptyTiming: false,
      expectPaymentLink: true,
    },
    {
      key: 'uzun-hata',
      path: longFailure,
      status: 'Ölü mektup',
      eventType: 'İade tamamlandı',
      failure: longFailureCode,
      expectForm: true,
      expectStale: false,
      expectEmptyTiming: true,
      expectPaymentLink: true,
    },
  ] as const;

  for (const scenario of scenarios) {
    for (const viewport of VIEWPORTS) {
      await page.setViewportSize(viewport);
      await page.goto(scenario.path);
      await expect(page.getByRole('heading', { level: 1, name: 'Webhook detayı' })).toBeVisible();
      const back = page.locator('a.admin-webhook-detail__back');
      await expect(back).toBeVisible();
      await expect(back).toHaveAttribute('href', '/yonetim/webhook');
      await expect(back).toHaveText('Webhook listesine dön');
      if (viewport.width < 1024) {
        const backBox = await back.boundingBox();
        expect(backBox).toBeTruthy();
        expect(backBox!.height).toBeGreaterThanOrEqual(44);
      }

      await expect(page.locator('.admin-webhook-detail__status')).toContainText(scenario.status);
      await expect(page.locator('.admin-webhook-detail__event-type')).toHaveText(scenario.eventType);
      await expect(page.locator('.admin-webhook-detail__environment')).toHaveText('Test (sandbox)');
      await expect(page.locator('.admin-webhook-detail__failure')).toHaveText(scenario.failure);

      const nextRetry = await factText(page, 'Sonraki deneme');
      const lease = await factText(page, 'Lease bitiş');
      if (scenario.expectEmptyTiming) {
        expect(nextRetry).toBe('—');
        expect(lease).toBe('—');
      } else {
        expect(lease).not.toBe('—');
      }

      if (scenario.expectStale) {
        await expect(page.locator('.admin-webhook-detail__stale')).toHaveText('Kilitleme süresi doldu');
      } else {
        await expect(page.locator('.admin-webhook-detail__stale')).toHaveCount(0);
      }

      const form = page.locator('form[name="admin_webhook_dead_letter_requeue"]');
      if (scenario.expectForm) {
        await expect(form).toBeVisible();
        await expect(page.locator('.admin-webhook-detail__requeue-note')).toContainText('doğrudan sağlayıcıya HTTP çağrısı yapmaz');
        const submit = page.locator('button.admin-webhook-detail__requeue-submit');
        await expect(submit).toBeVisible();
        await expect(submit).toHaveText('Yeniden kuyruğa al');
        if (viewport.width < 1024) {
          const box = await submit.boundingBox();
          expect(box).toBeTruthy();
          expect(box!.height).toBeGreaterThanOrEqual(44);
        }
      } else {
        await expect(form).toHaveCount(0);
      }

      if (scenario.expectPaymentLink) {
        const payment = page.locator('a.admin-webhook-detail__payment-link');
        await expect(payment).toBeVisible();
        await expect(payment).toHaveAttribute('href', /\/yonetim\/odemeler\//);
      }

      await expect(page.getByText('payload_hash')).toHaveCount(0);
      await expect(page.getByText('signature_fingerprint')).toHaveCount(0);
      await expect(page.getByText('provider_event_reference')).toHaveCount(0);
      await expect(page.getByText('br-whd-')).toHaveCount(0);

      if (viewport.width >= 1024) {
        const cards = page.locator('.admin-webhook-detail__card');
        await expect(cards).toHaveCount(4);
        const a = await cards.nth(0).boundingBox();
        const b = await cards.nth(1).boundingBox();
        expect(a && b).toBeTruthy();
        expect(boxesOverlap(a!, b!)).toBeFalsy();
      }

      if (scenario.key === 'uzun-hata') {
        await assertFailureFits(page, scenario.failure);
        await expect(page.locator('a.admin-webhook-detail__payment-link')).toHaveAttribute('href', paymentAttemptPath);
      }

      await assertLayout(page);
      await capture(page, info, `admin-webhook-detay-${scenario.key}-${viewport.name}.png`);
    }
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(deadLetter);
  const back = page.locator('a.admin-webhook-detail__back');
  await back.focus();
  expect(await back.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  const submit = page.locator('button.admin-webhook-detail__requeue-submit');
  await submit.focus();
  expect(await submit.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  // Never submit requeue in browser acceptance.
  await expect(page.locator('form[name="admin_webhook_dead_letter_requeue"]')).toBeVisible();
  await assertClean(page);
});

async function factText(page: Page, label: string): Promise<string> {
  const text = await page.locator('dt', { hasText: label }).locator('xpath=following-sibling::dd[1]').innerText();

  return text.trim();
}

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y;
}

async function assertFailureFits(page: Page, failure: string): Promise<void> {
  const metrics = await page.evaluate((code) => {
    const el = document.querySelector('.admin-webhook-detail__failure') as HTMLElement | null;
    if (!el || !(el.textContent ?? '').includes(code)) {
      return null;
    }
    const card = el.closest('.admin-webhook-detail__card') as HTMLElement | null;
    const box = el.getBoundingClientRect();
    const cardBox = card?.getBoundingClientRect();
    return {
      overflow: card ? el.scrollWidth - card.clientWidth : 0,
      right: box.right - document.documentElement.clientWidth,
      cardOverflow: cardBox ? box.right - cardBox.right : 0,
      pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  }, failure);
  expect(metrics).not.toBeNull();
  expect(metrics!.overflow).toBeLessThanOrEqual(1);
  expect(metrics!.right).toBeLessThanOrEqual(1);
  expect(metrics!.cardOverflow).toBeLessThanOrEqual(1);
  expect(metrics!.pageOverflow).toBeLessThanOrEqual(1);
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
