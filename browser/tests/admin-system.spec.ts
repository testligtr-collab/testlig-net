import path from 'node:path';
import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const SYSTEM_PATH = '/yonetim/sistem';

test('admin system summary layout, role visibility, and status chips', async ({ browser }, info) => {
  test.setTimeout(240_000);
  const data = manifest();

  // Browser has no safe probe seam for degraded/unavailable/migration-not-current.
  // Those status chips are covered by HTTP/presentation tests with a HealthChecker seam.

  const sa = await openRole(browser, data.users.superadmin);
  for (const viewport of VIEWPORTS) {
    await sa.setViewportSize(viewport);
    await sa.goto(SYSTEM_PATH);
    await expect(sa.getByRole('heading', { level: 1, name: 'Sistem durumu' })).toBeVisible();
    await expect(sa.locator('.admin-system-page')).toBeVisible();
    await expect(sa.getByRole('heading', { level: 2, name: 'Sağlık özeti' })).toBeVisible();
    await expect(sa.getByRole('heading', { level: 2, name: 'Webhook kuyruğu' })).toBeVisible();
    const healthFacts = sa.locator('#admin-system-health-heading').locator('xpath=ancestor::section').locator('dl.admin-system-page__facts');
    await expect(healthFacts).toContainText('Uygulama');
    await expect(healthFacts).toContainText('Veritabanı');
    await expect(healthFacts).toContainText('Redis');
    await expect(healthFacts).toContainText('Migrasyon');
    await expect(healthFacts.locator('.admin-status').filter({ hasText: /Çalışıyor|Uyarı|Kullanılamıyor|Güncel/ }).first()).toBeVisible();
    await expect(sa.locator('#admin-system-webhook-heading').locator('xpath=ancestor::section')).toContainText('Zamanı gelen');
    await expect(sa.locator('#admin-system-webhook-heading').locator('xpath=ancestor::section')).toContainText('Teslim edilemeyen');
    await expect(sa.getByText('DATABASE_URL')).toHaveCount(0);
    await expect(sa.getByText('REDIS_URL')).toHaveCount(0);
    await expect(sa.getByText('system_health_canary_secret')).toHaveCount(0);
    await expect(sa.getByText('exception_class')).toHaveCount(0);
    await expect(sa.locator('form[action="/yonetim/sistem"]')).toHaveCount(0);
    await expect(sa.locator('button[type="submit"]')).toHaveCount(0);

    const layout = sa.locator('.admin-system-page__layout');
    const columns = await layout.evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    if (viewport.width >= 900) {
      expect(columns).toBeGreaterThanOrEqual(2);
    } else {
      expect(columns).toBe(1);
    }

    const healthCard = sa.locator('.admin-system-page__card').first();
    const webhookCard = sa.locator('#admin-system-webhook-heading').locator('xpath=ancestor::section[contains(@class,"admin-system-page__card")]');
    const healthBox = await healthCard.boundingBox();
    const webhookBox = await webhookCard.boundingBox();
    expect(healthBox && webhookBox).toBeTruthy();
    expect(boxesOverlap(healthBox!, webhookBox!)).toBeFalsy();

    if (viewport.width >= 1024) {
      const sistemNav = sa.locator('#panel-sidebar a[href="/yonetim/sistem"]');
      await expect(sistemNav).toBeVisible();
      await sistemNav.focus();
      const focusStyle = await sistemNav.evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(focusStyle).not.toBe('none');
    } else {
      const menu = sa.getByRole('button', { name: 'Menü' });
      await expect(menu).toBeVisible();
      const box = await menu.boundingBox();
      expect(box).toBeTruthy();
      expect(box!.height).toBeGreaterThanOrEqual(40);
      await menu.focus();
      const focusStyle = await menu.evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(focusStyle).not.toBe('none');
    }

    await assertLayout(sa);
    await capture(sa, info, `sistem-sa-${viewport.name}.png`);
  }
  await sa.context().close();

  const admin = await openRole(browser, data.users.admin);
  for (const viewport of VIEWPORTS) {
    await admin.setViewportSize(viewport);
    await admin.goto(SYSTEM_PATH);
    await expect(admin.getByRole('heading', { level: 1, name: 'Sistem durumu' })).toBeVisible();
    await expect(admin.locator('.admin-system-page')).toBeVisible();
    await expect(admin.getByRole('heading', { level: 2, name: 'Sağlık özeti' })).toBeVisible();
    await expect(admin.getByRole('heading', { level: 2, name: 'Webhook kuyruğu' })).toHaveCount(0);
    await expect(admin.getByText('Webhook kuyruğu')).toHaveCount(0);
    await expect(admin.locator('.admin-status').first()).toBeVisible();
    await expect(admin.getByText('DATABASE_URL')).toHaveCount(0);
    await expect(admin.getByText('system_health_canary_secret')).toHaveCount(0);

    const cards = admin.locator('.admin-system-page__card');
    await expect(cards).toHaveCount(1);

    await assertLayout(admin);
    await capture(admin, info, `sistem-admin-${viewport.name}.png`);
  }
  await assertClean(admin);
  await admin.context().close();
});

async function openRole(browser: Browser, email: string): Promise<Page> {
  const page = await browser.newPage();
  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, email);
  return page;
}

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
