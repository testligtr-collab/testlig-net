import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { assertClean, assertLayout, installVideoAbort, manifest, shot, watch } from './support';

// M29-cms: homepage education-level cards lead to the published grades of their own level.
// Browser fixtures publish exactly one grade (1. sınıf Matematik): Ilkokul has a real target, every other level
// shows the preparation state. The fixtures mirror the publication rules; they are not the live catalog.

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
  writeFileSync(path.join(dir, 'home-level-cards-overflow.txt'), `${measurements.join('\n')}\n`);
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

async function showLevels(page: Page): Promise<void> {
  // The page uses smooth scrolling; disable it through the CSSOM (no CSP-relevant inline style) so screenshots land on the section.
  await page.evaluate(() => document.documentElement.style.setProperty('scroll-behavior', 'auto', 'important'));
  await page.locator('#seviyeler').evaluate((node) => node.scrollIntoView({ block: 'start' }));
  await expect(async () => {
    const first = await page.evaluate(() => window.scrollY);
    await page.waitForTimeout(100);
    expect(await page.evaluate(() => window.scrollY)).toBe(first);
  }).toPass({ timeout: 5_000 });
}

test('anonymous visitor follows the Ilkokul card to real 1. sınıf Matematik; other levels show preparation without a CTA', async ({ page }, info: TestInfo) => {
  const paths = manifest().paths;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    const label = viewport.name;

    const home = await page.goto('/');
    expect(home?.status()).toBe(200);
    await assertLayout(page);
    await assertClean(page);
    await showLevels(page);

    await expect(page.locator('#seviyeler article.hp-vcard')).toHaveCount(4);
    // The only link inside the level section is the Ilkokul target; nothing points at the unfiltered catalog.
    await expect(page.locator('#seviyeler a')).toHaveCount(1);
    await expect(page.locator('#seviyeler a[href="/dersler"]')).toHaveCount(0);

    const ilkokul = page.locator('#ilkokul');
    const cta = ilkokul.getByRole('link', { name: '1. sınıf derslerini gör' });
    await expect(cta).toBeVisible();
    await expect(cta).toHaveAttribute('href', paths.publicGrade);
    await expect(ilkokul.getByTestId('level-note')).toHaveText('Şu an yayımlanmış sınıf: 1.');

    for (const id of ['ortaokul', 'lise', 'universite-hazirlik']) {
      const card = page.locator(`#${id}`);
      await expect(card.getByRole('status')).toHaveText('İçerikler hazırlanıyor');
      await expect(card.locator('a, button')).toHaveCount(0);
    }
    await overflow(page, `${label} home seviyeler`);
    await shot(page, info, 'home-level', 'kartlar', viewport.name);

    await cta.click();
    await page.waitForURL((url) => url.pathname === paths.publicGrade && url.search === '');
    await expect(page.getByRole('heading', { level: 1, name: '1. sınıf dersleri' })).toBeVisible();
    await assertLayout(page);
    await overflow(page, `${label} ${paths.publicGrade}`);
    await shot(page, info, 'home-level', 'sinif-1', viewport.name);

    await page.locator('.catalog-panel', { hasText: 'Matematik' }).getByRole('link', { name: 'Dersi incele' }).click();
    await page.waitForURL((url) => url.pathname === paths.publicSubject);
    await expect(page.getByRole('heading', { level: 1, name: 'Matematik' })).toBeVisible();
    await assertLayout(page);
    await shot(page, info, 'home-level', 'matematik', viewport.name);
  }
});

test('level filter on /dersler is scoped, shows preparation for empty levels and rejects unknown values', async ({ page }, info: TestInfo) => {
  const paths = manifest().paths;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    const ilkokul = await page.goto('/dersler?kademe=ilkokul');
    expect(ilkokul?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'İlkokul dersleri' })).toBeVisible();
    await expect(page.locator('.catalog-grid a')).toHaveCount(1);
    await expect(page.locator('.catalog-grid a')).toHaveAttribute('href', paths.publicGrade);
    await expect(page.getByRole('link', { name: 'Tüm sınıfları gör' })).toHaveAttribute('href', '/dersler');
    await assertLayout(page);
    await overflow(page, `${viewport.name} kademe=ilkokul`);
    await shot(page, info, 'home-level', 'filtre-ilkokul', viewport.name);

    const ortaokul = await page.goto('/dersler?kademe=ortaokul');
    expect(ortaokul?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Ortaokul dersleri' })).toBeVisible();
    await expect(page.locator('.catalog-empty')).toContainText('Ortaokul için içerikler hazırlanıyor');
    await expect(page.locator('.catalog-grid')).toHaveCount(0);
    await assertLayout(page);
    await overflow(page, `${viewport.name} kademe=ortaokul`);
    await shot(page, info, 'home-level', 'filtre-ortaokul-hazirlaniyor', viewport.name);

    const invalid = await page.goto('/dersler?kademe=universite-hazirlik');
    expect(invalid?.status()).toBe(404);

    // Backward compatible: the unfiltered catalog and direct grade URL are unchanged.
    const all = await page.goto('/dersler');
    expect(all?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Dersleri keşfet' })).toBeVisible();
    const grade = await page.goto(paths.publicGrade);
    expect(grade?.status()).toBe(200);
  }
});

test('Ilkokul card is keyboard reachable with a visible focus indicator and Enter opens the grade', async ({ page }, info: TestInfo) => {
  const paths = manifest().paths;

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/');
    await showLevels(page);

    const cta = page.locator('#ilkokul').getByRole('link', { name: '1. sınıf derslerini gör' });
    await cta.focus();
    await expect(cta).toBeFocused();
    const style = await cta.evaluate((node) => {
      const computed = getComputedStyle(node);
      return { outlineStyle: computed.outlineStyle, outlineWidth: computed.outlineWidth, background: computed.backgroundColor };
    });
    const hasOutline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
    expect(hasOutline || style.background !== 'rgba(0, 0, 0, 0)', 'focused card link must show a focus indicator').toBe(true);
    await shot(page, info, 'home-level', 'klavye-odak', viewport.name);

    // Preparing cards expose no focusable control, so Tab from the Ilkokul link skips them.
    await page.keyboard.press('Tab');
    const focusedInsideOtherLevel = await page.evaluate(() => {
      const active = document.activeElement;
      return Boolean(active && active.closest('#ortaokul, #lise, #universite-hazirlik'));
    });
    expect(focusedInsideOtherLevel).toBe(false);

    await cta.focus();
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => url.pathname === paths.publicGrade);
    await expect(page.getByRole('heading', { level: 1, name: '1. sınıf dersleri' })).toBeVisible();
  }
});
