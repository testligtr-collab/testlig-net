import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin news list layout, statuses, empty, long text, and category errors', async ({ page, browser }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const longTitle = data.paths.newsLongTitle;
  const longCategory = data.paths.newsLongCategory;

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/haberler');
    await expect(page.getByRole('heading', { level: 1, name: 'Haberler' })).toBeVisible();
    await expect(page.getByText('Haber taslaklarını ve yayın durumlarını yönetin.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni haber', exact: true })).toBeVisible();
    await expect(page.getByText('Henüz haber yok')).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-haberler-bos-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/haberler');
  await createCategory(page, 'Duyurular');
  await createCategory(page, 'Etkinlikler');
  await createCategory(page, 'Genel');
  await createCategory(page, longCategory);

  await createDraft(page, 'Ornek haber basligi', 'Duyurular', 'Taslak ozeti yalniz yonetimde kalir.', 'Taslak govdesi yalniz yonetimde kalir.');
  const reviewPath = await createDraft(page, 'Ornek etkinlik duyurusu', 'Etkinlikler', 'Inceleme ozeti yalniz yonetimde kalir.', 'Inceleme govdesi yayinlanmadan once bekler.');
  await page.goto(reviewPath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  const publishPath = await createDraft(page, 'Ornek platform haberi', 'Genel', 'Yayin ozeti baska yoneticinin onayini bekler.', 'Yayin govdesi baska yoneticinin onayini bekler.');
  await page.goto(publishPath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  const archivePath = await createDraft(page, 'Arsivlenmis ornek haber', 'Genel', 'Arsiv ozeti liste durumunda gorunur.', 'Arsiv govdesi yayin sonrasi arsivlenir.');
  await page.goto(archivePath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  await createDraft(page, longTitle, longCategory, 'Uzun ozet metni listeye basilmaz.', 'Uzun govde metni listeye basilmaz.');

  const saContext = await browser.newContext();
  const saPage = await saContext.newPage();
  installVideoAbort(saPage);
  await watch(saPage);
  await login(saPage, data.users.superadmin);
  await saPage.goto(publishPath);
  await saPage.getByRole('button', { name: 'Yayımla' }).click();
  await saPage.waitForURL(/\/yonetim\/haberler\//);
  await saPage.goto(archivePath);
  await saPage.getByRole('button', { name: 'Yayımla' }).click();
  await saPage.waitForURL(/\/yonetim\/haberler\//);
  await saPage.getByRole('button', { name: 'Arşivle' }).click();
  await saPage.waitForURL(/\/yonetim\/haberler\//);
  await saContext.close();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/haberler');
    await expect(page.getByRole('heading', { level: 2, name: 'Haber listesi' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Kategoriler' })).toBeVisible();
    await expect(page.getByText('Ornek haber basligi').filter({ visible: true }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Taslak' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'İncelemede' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Yayında' }).first()).toBeVisible();
    await expect(page.locator('.admin-status:visible', { hasText: 'Arşivlenmiş' }).first()).toBeVisible();
    await expect(page.getByText(longTitle).filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText(longCategory).filter({ visible: true }).first()).toBeVisible();
    await expect(page.getByText('Uzun ozet metni listeye basilmaz.')).toHaveCount(0);

    const row = page.locator('tr', { hasText: longTitle });
    if (viewport.width >= 1024) {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      const statusBox = await row.locator('.admin-news__status').boundingBox();
      const actionBox = await row.locator('.admin-news__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      expect(boxesOverlap(statusBox!, actionBox!)).toBeFalsy();
      expect(await oneLine(row.locator('.admin-news__status .admin-status'))).toBeTruthy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const open = row.locator('.admin-news__open');
      await expect(open).toBeVisible();
      const openBox = await open.boundingBox();
      expect(openBox).toBeTruthy();
      expect(openBox!.height).toBeGreaterThanOrEqual(44);
    }

    const listBox = await page.locator('.admin-news-list').boundingBox();
    const categoriesBox = await page.locator('.admin-news-categories').boundingBox();
    expect(listBox && categoriesBox).toBeTruthy();
    if (viewport.width >= 1024) {
      expect(categoriesBox!.height).toBeLessThan(listBox!.height + 80);
    } else {
      expect(categoriesBox!.y).toBeGreaterThan(listBox!.y);
    }

    await assertLayout(page);
    await capture(page, info, `admin-haberler-${viewport.name}.png`);
    await capture(page, info, `admin-haberler-uzun-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/haberler');
    await page.locator('#news-category-name').fill('a');
    await page.getByRole('button', { name: 'Kategori ekle' }).click();
    await page.waitForURL('/yonetim/haberler');
    await expect(page.getByText('Başlık, özet veya metin geçersiz. HTML kullanılamaz.')).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-haberler-kategori-hata-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/haberler');
  const createLink = page.getByRole('link', { name: 'Yeni haber', exact: true });
  await createLink.focus();
  expect(await createLink.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  const categoryInput = page.locator('#news-category-name');
  await categoryInput.focus();
  expect(await categoryInput.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await expect(page.locator('label[for="news-category-name"]')).toBeVisible();

  await assertClean(page);
});

async function createCategory(page: Page, name: string): Promise<void> {
  await page.goto('/yonetim/haberler');
  await page.locator('#news-category-name').fill(name);
  await page.getByRole('button', { name: 'Kategori ekle' }).click();
  await page.waitForURL('/yonetim/haberler');
  await expect(page.getByText(name).first()).toBeVisible();
}

async function createDraft(page: Page, title: string, categoryLabel: string, summary: string, body: string): Promise<string> {
  await page.goto('/yonetim/haberler/yeni');
  await page.locator('input[name="title"]').fill(title);
  await page.locator('input[name="summary"]').fill(summary);
  await page.locator('textarea[name="body"]').fill(body);
  await page.locator('select[name="category_id"]').selectOption({ label: categoryLabel });
  await page.getByRole('button', { name: 'Taslağı kaydet' }).click();
  await page.waitForURL(/\/yonetim\/haberler\/[0-9a-fA-F-]+$/);

  return new URL(page.url()).pathname;
}

async function oneLine(locator: Locator): Promise<boolean> {
  return locator.evaluate((node) => {
    const texts = [...node.childNodes].filter((child) => child.nodeType === Node.TEXT_NODE && (child.textContent ?? '').trim() !== '');
    const target = texts.length > 0 ? texts[texts.length - 1] : node;
    const range = document.createRange();
    range.selectNodeContents(target);
    const rects = [...range.getClientRects()].filter((rect) => rect.width > 0 && rect.height > 0);
    const top = rects[0]?.top ?? 0;

    return rects.length > 0 && rects.every((rect) => Math.abs(rect.top - top) < 4);
  });
}

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width - 1 && a.x + a.width > b.x + 1 && a.y < b.y + b.height - 1 && a.y + a.height > b.y + 1;
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 2400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
