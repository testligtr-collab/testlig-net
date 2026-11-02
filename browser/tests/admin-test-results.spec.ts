import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin test results keep the open layout and the completed-run gate', async ({ page }, info) => {
  test.setTimeout(360_000);
  const data = manifest();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResults);
    await expect(page.getByRole('heading', { level: 1, name: 'Sentetik sonuc testi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Test detayına dön' })).toBeVisible();
    await expect(page.getByText(data.paths.testResultStudent)).toBeVisible();
    await expect(page.getByText('Tamamlandı', { exact: true })).toBeVisible();
    await expect(page.getByText('Devam ediyor', { exact: true })).toBeVisible();
    await expect(page.getByText('İptal', { exact: true })).toBeVisible();
    await expect(page.getByText('Süre doldu', { exact: true })).toHaveCount(2);
    await expect(page.locator('tr', { hasText: 'Sure Doldu' })).toContainText('2,5');
    await expect(page.locator('tr', { hasText: 'Bos Deneme' })).toContainText('—');
    await expect(page.locator('tr', { hasText: 'Bos Deneme' })).not.toContainText('2,5');
    await expect(page.locator('tr', { hasText: 'Devam Eden' })).toContainText('—');
    await expect(page.getByText('henüz sonuç yok')).toHaveCount(0);
    await expect(page.getByText('Doğru cevap')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await expect(page.getByText('browser-result-done@example.test')).toHaveCount(0);
    const links = page.getByRole('link', { name: 'İncele' });
    await expect(links).toHaveCount(5);
    const open = await links.first().boundingBox();
    expect(open?.height).toBeGreaterThanOrEqual(44);
    if (viewport.width >= 1024) {
      await expect(page.locator('.admin-test-results__table th', { hasText: 'Öğrenci' })).toBeVisible();
      await expect(page.locator('.admin-result-field__label').first()).toBeHidden();
      const action = await links.first().evaluate((node) => getComputedStyle(node).whiteSpace);
      expect(action).toBe('nowrap');
    } else {
      await expect(page.locator('.admin-test-results__table thead')).toBeHidden();
      await expect(page.locator('.admin-result-field__label').first()).toBeVisible();
      const row = await page.locator('.admin-test-results__table tbody tr').first().boundingBox();
      expect(row).toBeTruthy();
      expect(row!.width).toBeLessThanOrEqual(viewport.width);
    }
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-liste-${viewport.name}.png`);
    await capture(page, info, `admin-test-sonuc-karisik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultsEmpty);
    await expect(page.getByText('Bu test için henüz sonuç yok.')).toBeVisible();
    await expect(page.getByText('Aramanla eşleşen sonuç yok.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-bos-${viewport.name}.png`);

    await page.goto(`${data.paths.testResults}?q=Yokboyle`);
    await expect(page.getByText('Aramanla eşleşen sonuç yok.')).toBeVisible();
    await expect(page.getByText('Bu test için henüz sonuç yok.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-arama-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultCompleted);
    await expect(page.getByRole('heading', { level: 1, name: 'Sentetik sonuc testi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sonuç listesine dön' })).toBeVisible();
    await expect(page.getByText('Kalın kök')).toBeVisible();
    await expect(page.getByText('Liste maddesi bir')).toBeVisible();
    await expect(page.locator('.q-frac')).toBeVisible();
    await expect(page.getByText('Öğrencinin cevabı')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: 'Çözüm' })).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await expect(page.getByText('opt_')).toHaveCount(0);
    const column = await page.locator('.admin-test-results--detail').boundingBox();
    expect(column).toBeTruthy();
    if (viewport.width >= 1024) {
      expect(column!.width).toBeLessThanOrEqual(780);
    }
    const math = await page.locator('.q-math').first().boundingBox();
    const card = await page.locator('.admin-test-results__question').first().boundingBox();
    expect(math && card).toBeTruthy();
    expect(math!.x).toBeGreaterThanOrEqual(card!.x - 1);
    expect(math!.x + math!.width).toBeLessThanOrEqual(card!.x + card!.width + 1);
    const levels = await page.locator('main h1, main h2, main h3').evaluateAll((nodes) => nodes.map((node) => Number(node.tagName.slice(1))));
    expect(levels[0]).toBe(1);
    for (let index = 1; index < levels.length; index += 1) {
      expect(levels[index]).toBeLessThanOrEqual(levels[index - 1] + 1);
    }
    if (viewport.name === '1440') {
      await page.getByRole('link', { name: 'Sonuç listesine dön' }).focus();
      const outline = await page.getByRole('link', { name: 'Sonuç listesine dön' }).evaluate((node) => getComputedStyle(node).outlineStyle);
      expect(outline).not.toBe('none');
    }
    await measureFormula(page, info, viewport.name);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-detay-${viewport.name}.png`);
    await capture(page, info, `admin-test-sonuc-detay-bicim-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResultOpen);
    await expect(page.getByText('Bu deneme henüz tamamlanmadı.')).toBeVisible();
    await expect(page.getByText('Devam ediyor')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toHaveCount(0);
    await expect(page.getByText('Çözüm')).toHaveCount(0);
    await expect(page.getByText('Kalın kök')).toHaveCount(0);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-detay-acik-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS.filter((item) => item.name === '1440' || item.name === '390')) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.testResults);
    await expect(page.locator('tr', { hasText: 'Sure Doldu' })).toContainText('Süre doldu');
    await expect(page.locator('tr', { hasText: 'Bos Deneme' })).toContainText('Süre doldu');
    await expect(page.locator('tr', { hasText: 'Iptal Edilen' })).toContainText('İptal');
    await expect(page.getByText('Bu test için henüz sonuç yok.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-sure-liste-${viewport.name}.png`);

    await page.goto(data.paths.testResultExpired);
    await expect(page.getByText('Sure Doldu')).toBeVisible();
    await expect(page.getByText('Süre doldu')).toBeVisible();
    await expect(page.getByText('2,5 / 2,5')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toBeVisible();
    await expect(page.getByRole('heading', { level: 3, name: 'Çözüm' })).toBeVisible();
    await expect(page.getByText('Bu deneme henüz tamamlanmadı.')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-sure-detay-${viewport.name}.png`);

    await page.goto(data.paths.testResultExpiredClosed);
    await expect(page.getByText('Bos Deneme')).toBeVisible();
    await expect(page.getByText('Süre doldu')).toBeVisible();
    await expect(page.getByText('Bu deneme henüz tamamlanmadı.')).toBeVisible();
    await expect(page.getByText('Doğru cevap')).toHaveCount(0);
    await expect(page.getByText('Çözüm')).toHaveCount(0);
    await expect(page.getByText('2,5')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-test-sonuc-sure-kapali-${viewport.name}.png`);
  }

  await assertClean(page);
});

async function measureFormula(page: Page, info: TestInfo, viewportName: string): Promise<void> {
  await page.evaluate(() => window.dispatchEvent(new Event('resize')));
  const math = page.locator('.admin-test-results__stem .q-math').first();
  const shortMath = page.locator('.admin-test-results__solution .q-math').first();
  const before = await readMath(math);
  const short = await readMath(shortMath);
  const pageBox = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(pageBox.scrollWidth).toBeLessThanOrEqual(pageBox.clientWidth + 1);
  expect(short.overflows).toBe(false);
  expect(short.tab).toBeNull();
  let after: MathBox | null = null;
  if (before.overflows) {
    expect(before.tab).toBe('0');
    expect(before.label).toBe('Formül');
    expect(before.role).toBe('group');
    if (viewportName === '390') {
      await shot(info, page.locator('.admin-test-results__question').first(), 'admin-test-sonuc-formul-390.png');
    }
    await math.focus();
    expect(await math.evaluate((node) => document.activeElement === node)).toBe(true);
    await math.evaluate((node) => {
      node.scrollLeft = 0;
    });
    await page.keyboard.press('ArrowRight');
    const keyed = await math.evaluate((node) => node.scrollLeft);
    expect(keyed).toBeGreaterThan(0);
    await page.keyboard.press('End');
    after = await math.evaluate((node) => {
      const tailVisible = (target: HTMLElement): boolean => {
        const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
        let current: Node | null = null;
        let last: Text | null = null;
        while ((current = walker.nextNode())) {
          if (current.textContent && current.textContent.trim() && current instanceof Text) {
            last = current;
          }
        }
        if (!last) {
          return false;
        }
        const range = document.createRange();
        range.selectNodeContents(last);
        const rects = range.getClientRects();
        const tail = rects[rects.length - 1];
        const box = target.getBoundingClientRect();
        return Boolean(tail) && tail.right <= box.right + 2 && tail.right > box.left;
      };
      return {
        scrollWidth: node.scrollWidth,
        clientWidth: node.clientWidth,
        scrollLeft: node.scrollLeft,
        start: 0,
        tab: node.getAttribute('tabindex'),
        label: node.getAttribute('aria-label'),
        role: node.getAttribute('role'),
        overflows: node.scrollWidth > node.clientWidth + 1,
        endVisible: tailVisible(node),
      };
    });
    expect(after.scrollLeft).toBeGreaterThanOrEqual(keyed);
    expect(after.scrollLeft + after.clientWidth).toBeGreaterThanOrEqual(after.scrollWidth - 2);
    expect(after.endVisible).toBe(true);
    if (viewportName === '390') {
      await shot(info, page.locator('.admin-test-results__question').first(), 'admin-test-sonuc-formul-390-son.png');
    }
  } else {
    expect(before.endVisible).toBe(true);
    expect(before.tab).toBeNull();
    if (viewportName === '390') {
      await shot(info, page.locator('.admin-test-results__question').first(), 'admin-test-sonuc-formul-390.png');
    }
  }
  await info.attach(`formul-${viewportName}.json`, {
    body: JSON.stringify({ page: pageBox, before, after, short }),
    contentType: 'application/json',
  });
  await page.evaluate(() => window.scrollTo(0, 0));
}

async function readMath(locator: Locator): Promise<MathBox> {
  return locator.evaluate((node) => {
    const tailVisible = (target: HTMLElement): boolean => {
      const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
      let current: Node | null = null;
      let last: Text | null = null;
      while ((current = walker.nextNode())) {
        if (current.textContent && current.textContent.trim() && current instanceof Text) {
          last = current;
        }
      }
      if (!last) {
        return false;
      }
      const range = document.createRange();
      range.selectNodeContents(last);
      const rects = range.getClientRects();
      const tail = rects[rects.length - 1];
      const box = target.getBoundingClientRect();
      return Boolean(tail) && tail.right <= box.right + 2 && tail.right > box.left;
    };
    return {
      scrollWidth: node.scrollWidth,
      clientWidth: node.clientWidth,
      scrollLeft: node.scrollLeft,
      tab: node.getAttribute('tabindex'),
      label: node.getAttribute('aria-label'),
      role: node.getAttribute('role'),
      overflows: node.scrollWidth > node.clientWidth + 1,
      endVisible: tailVisible(node),
    };
  });
}

async function shot(info: TestInfo, locator: Locator, name: string): Promise<void> {
  const file = path.join('artifacts', 'screenshots', name);
  await locator.screenshot({ path: file });
  await info.attach(name, { path: file, contentType: 'image/png' });
}

type MathBox = {
  scrollWidth: number;
  clientWidth: number;
  scrollLeft: number;
  start?: number;
  tab: string | null;
  label: string | null;
  role: string | null;
  overflows: boolean;
  endVisible: boolean;
};

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 6400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
