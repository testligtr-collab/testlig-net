import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { assertClean, assertLayout, installVideoAbort, manifest, shot, watch } from './support';

// M29-cms: the homepage "Sınıfını seç" section is wired to the real public catalog.
// Browser fixtures publish exactly one grade (1. sınıf Matematik); every other grade must show the
// preparation state. The fixtures are not the live data; they only mirror the publication rules.

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 900 },
] as const;

const measurements: string[] = [];

test.beforeEach(async ({ page }) => {
  installVideoAbort(page);
  await watch(page);
});

test.afterAll(() => {
  if (measurements.length === 0) {
    return;
  }
  const dir = path.join('artifacts', 'screenshots');
  mkdirSync(dir, { recursive: true });
  writeFileSync(path.join(dir, 'home-grade-catalog-overflow.txt'), `${measurements.join('\n')}\n`);
});

async function overflow(page: Page, label: string): Promise<void> {
  const metrics = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  const diff = metrics.scrollWidth - metrics.clientWidth;
  measurements.push(`${label}: scrollWidth=${metrics.scrollWidth} clientWidth=${metrics.clientWidth} diff=${diff}`);
  expect(diff, `${label} horizontal overflow`).toBeLessThanOrEqual(1);
}

async function selectGrade(page: Page, grade: number): Promise<void> {
  const tab = page.locator(`#tab-${grade}`);
  // Stimulus attaches after load, so retry until the click actually switched the panel.
  await expect(async () => {
    await tab.click();
    await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 1000 });
  }).toPass({ timeout: 15_000 });
  await expect(page.locator(`#grade-panel-${grade}`)).toBeVisible();
}

async function showSection(page: Page): Promise<void> {
  await page.locator('#siniflar').evaluate((node) => node.scrollIntoView({ block: 'start' }));
}

test('anonymous visitor selects 1. sınıf, follows the catalog CTA and reaches Matematik; an empty grade shows preparation', async ({ page }, info: TestInfo) => {
  const paths = manifest().paths;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const label = `${viewport.name}`;

    const home = await page.goto('/');
    expect(home?.status()).toBe(200);
    await assertLayout(page);
    await assertClean(page);
    await showSection(page);

    await expect(page.getByRole('tablist', { name: 'Sınıf seçimi' })).toBeVisible();
    await expect(page.getByRole('tab')).toHaveCount(8);
    await expect(page.locator('#siniflar .hp-note')).not.toContainText('örnek gösterim');

    // Grade without published content: preparation state, no catalog CTA, not colour-only.
    await selectGrade(page, 4);
    const emptyPanel = page.locator('#grade-panel-4');
    await expect(emptyPanel).toContainText('İçerikler hazırlanıyor');
    await expect(emptyPanel.locator('a, button')).toHaveCount(0);
    await expect(page.locator('#grade-panel-1')).toBeHidden();
    await expect(page.locator('#tab-4')).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('#tab-1')).toHaveAttribute('aria-selected', 'false');
    await expect(page.locator('#tab-4')).toHaveAttribute('aria-label', /hazırlanıyor/);
    await overflow(page, `${label} home sinif-4 hazirlaniyor`);
    await shot(page, info, 'home-grade', 'hazirlaniyor', viewport.name);

    // Grade with published Matematik content: real catalog CTA.
    await selectGrade(page, 1);
    const panel = page.locator('#grade-panel-1');
    await expect(panel).toHaveAttribute('aria-labelledby', 'tab-1');
    await expect(page.locator('#tab-1')).toHaveAttribute('aria-controls', 'grade-panel-1');
    await expect(page.locator('#tab-1')).toHaveAttribute('aria-label', /dersler yayında/);
    const cta = panel.getByRole('link', { name: '1. sınıf derslerini gör' });
    await expect(cta).toBeVisible();
    await expect(cta).toHaveAttribute('href', paths.publicGrade);
    await expect(panel).toContainText('öğrenci hesabıyla açılır');
    await overflow(page, `${label} home sinif-1 hazir`);
    await shot(page, info, 'home-grade', 'sinif-1', viewport.name);

    await cta.click();
    await page.waitForURL((url) => url.pathname === paths.publicGrade);
    await expect(page.getByRole('heading', { level: 1, name: '1. sınıf dersleri' })).toBeVisible();
    await assertLayout(page);
    await overflow(page, `${label} ${paths.publicGrade}`);
    await shot(page, info, 'home-grade', 'katalog-sinif', viewport.name);

    await page.locator('.catalog-panel', { hasText: 'Matematik' }).getByRole('link', { name: 'Dersi incele' }).click();
    await page.waitForURL((url) => url.pathname === paths.publicSubject);
    await expect(page.getByRole('heading', { level: 1, name: 'Matematik' })).toBeVisible();
    await assertLayout(page);
    await overflow(page, `${label} ${paths.publicSubject}`);
    await shot(page, info, 'home-grade', 'matematik', viewport.name);

    await page.locator(`a[href="${paths.publicUnit}"]`).click();
    await page.waitForURL((url) => url.pathname === paths.publicUnit);
    await expect(page.getByRole('heading', { level: 1, name: 'Sayilar' })).toBeVisible();
    await assertLayout(page);
    await info.attach(`final-url-${viewport.name}.txt`, {
      body: `${page.url()}\n${await page.title()}`,
      contentType: 'text/plain',
    });
  }
});

test('grade tabs are reachable and operable by keyboard with a visible selected state', async ({ page }, info: TestInfo) => {
  const paths = manifest().paths;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/');
    await showSection(page);

    const first = page.locator('#tab-1');
    await expect(first).toHaveAttribute('tabindex', '0');
    await expect(page.locator('#tab-2')).toHaveAttribute('tabindex', '-1');
    await first.focus();
    await expect(first).toBeFocused();

    await expect(async () => {
      await page.keyboard.press('ArrowRight');
      await expect(page.locator('#tab-2')).toHaveAttribute('aria-selected', 'true', { timeout: 1000 });
    }).toPass({ timeout: 15_000 });
    await expect(page.locator('#tab-2')).toBeFocused();
    await expect(page.locator('#tab-2')).toHaveAttribute('tabindex', '0');
    await expect(page.locator('#tab-1')).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('#grade-panel-2')).toBeVisible();
    await expect(page.locator('#grade-panel-1')).toBeHidden();

    await page.keyboard.press('End');
    await expect(page.locator('#tab-8')).toBeFocused();
    await expect(page.locator('#tab-8')).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Home');
    await expect(page.locator('#tab-1')).toBeFocused();
    await expect(page.locator('#tab-1')).toHaveAttribute('aria-selected', 'true');

    // Tab leaves the tablist for the active panel, then reaches the focusable catalog link.
    await page.keyboard.press('Tab');
    await expect(page.locator('#grade-panel-1')).toBeFocused();
    await page.keyboard.press('Tab');
    const cta = page.locator('#grade-panel-1').getByRole('link', { name: '1. sınıf derslerini gör' });
    await expect(cta).toBeFocused();
    await shot(page, info, 'home-grade', 'klavye-odak', viewport.name);

    await page.keyboard.press('Enter');
    await page.waitForURL((url) => url.pathname === paths.publicGrade);
    await expect(page.getByRole('heading', { level: 1, name: '1. sınıf dersleri' })).toBeVisible();
  }
});

test('without JavaScript the selected panel and the noscript list still reach the real grade catalog', async ({ browser }) => {
  const paths = manifest().paths;
  for (const viewport of VIEWPORTS) {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: viewport.width, height: viewport.height } });
    const page = await context.newPage();
    await page.goto('/');

    await expect(page.locator('#grade-panel-1')).toBeVisible();
    await expect(page.locator('#grade-panel-1').getByRole('link', { name: '1. sınıf derslerini gör' })).toHaveAttribute('href', paths.publicGrade);
    const list = page.getByRole('list', { name: 'Sınıf dersleri' });
    await expect(list.getByRole('link')).toHaveCount(1);
    await expect(list).toContainText('4. sınıf: içerikler hazırlanıyor');

    await page.locator('#grade-panel-1').getByRole('link', { name: '1. sınıf derslerini gör' }).click();
    await page.waitForURL((url) => url.pathname === paths.publicGrade);
    await expect(page.getByRole('heading', { level: 1, name: '1. sınıf dersleri' })).toBeVisible();
    await context.close();
  }
});

test('shared #236 menu and mobile drawer keep working next to the grade selector', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  const toggle = page.getByRole('button', { name: 'Menüyü aç veya kapat' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const links = page.locator('nav[aria-label="Mobil ana menü"]').getByRole('link');
  await expect(links).toHaveText(['Ana Sayfa', 'Sınıflar', 'Haberler']);
  await page.keyboard.press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
  await page.locator('#siniflar').scrollIntoViewIfNeeded();
  await expect(page.getByRole('tab')).toHaveCount(8);

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/');
  await expect(page.locator('nav[aria-label="Ana menü"]').getByRole('link')).toHaveText(['Ana Sayfa', 'Sınıflar', 'Haberler']);
});
