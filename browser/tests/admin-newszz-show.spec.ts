import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('admin news detail layout, statuses, tech panel, error, and long text', async ({ page, browser }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/haberler');
  await page.locator('#news-category-name').fill('Detay Kategori');
  await page.getByRole('button', { name: 'Kategori ekle' }).click();
  await page.waitForURL('/yonetim/haberler');

  const draftPath = await createDraft(
    page,
    'Detay taslak basligi',
    'Detay Kategori',
    'Detay taslak ozeti burada durur.',
    "Detay taslak govdesi.\nIkinci satir burada kalir.",
  );
  const reviewPath = await createDraft(
    page,
    'Detay inceleme basligi',
    'Detay Kategori',
    'Detay inceleme ozeti burada durur.',
    'Detay inceleme govdesi yayinlanmadan once bekler.',
  );
  await page.goto(reviewPath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  const publishPath = await createDraft(
    page,
    'Detay yayin basligi',
    'Detay Kategori',
    'Detay yayin ozeti burada durur.',
    'Detay yayin govdesi baska yoneticinin onayini bekler.',
  );
  await page.goto(publishPath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  const archivePath = await createDraft(
    page,
    'Detay arsiv basligi',
    'Detay Kategori',
    'Detay arsiv ozeti burada durur.',
    'Detay arsiv govdesi yayin sonrasi arsivlenir.',
  );
  await page.goto(archivePath);
  await page.getByRole('button', { name: 'İncelemeye gönder' }).click();
  await page.waitForURL(/\/yonetim\/haberler\//);

  const longTitle = 'Uzun detay basligi dar ekranda baslikta ve yan kartta sarilir ve yatay tasmadan devam eder';
  const longSummary = 'Uzun detay ozeti yan kart kadar uzamaz ve ana okuma kolonunda kirilmadan sarilir.';
  const longBody = `${'Uzun detay govde satiri okunabilir genislikte kalir. '.repeat(18)}Son satir.\nYeni paragraf.`;
  const longPath = await createDraft(page, longTitle, 'Detay Kategori', longSummary, longBody);

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
    await page.goto(draftPath);
    await expect(page.getByRole('heading', { level: 1, name: 'Detay taslak basligi' })).toBeVisible();
    await expect(page.locator('.admin-news-show-head .admin-status', { hasText: 'Taslak' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Listeye dön/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Haberi düzenle' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Haber içeriği' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'İşlemler' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'İncelemeye gönder' })).toBeVisible();
    const edit = page.getByRole('link', { name: 'Haberi düzenle' });
    const editBox = await edit.boundingBox();
    expect(editBox).toBeTruthy();
    expect(editBox!.height).toBeGreaterThanOrEqual(44);
    await assertAsideHeight(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-taslak-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(reviewPath);
    await expect(page.locator('.admin-news-show-head .admin-status', { hasText: 'İncelemede' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Yayımla' })).toBeDisabled();
    await expect(page.getByText('Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.')).toBeVisible();
    await assertAsideHeight(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-inceleme-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(publishPath);
    await expect(page.locator('.admin-news-show-head .admin-status', { hasText: 'Yayında' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Sitedeki sayfayı aç' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Arşivle' })).toBeVisible();
    await assertAsideHeight(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-yayin-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(archivePath);
    await expect(page.locator('.admin-news-show-head .admin-status', { hasText: 'Arşivlenmiş' })).toBeVisible();
    await expect(page.getByText('Bu haber arşivde. Sitede görünmez.')).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'İşlemler' })).toHaveCount(0);
    await assertAsideHeight(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-arsiv-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(longPath);
    await expect(page.getByRole('heading', { level: 1, name: longTitle })).toBeVisible();
    await expect(page.getByText(longSummary)).toBeVisible();
    await expect(page.locator('.admin-news-show-body__text')).toContainText('Uzun detay govde satiri');
    await assertAsideHeight(page, viewport.width);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-uzun-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(draftPath);
    const tech = page.locator('details.admin-news-show-tech');
    await expect(tech.locator('summary')).toBeVisible();
    await tech.locator('summary').click();
    await expect(tech).toHaveAttribute('open', '');
    await expect(tech.locator('code').first()).toBeVisible();
    const summaryBox = await tech.locator('summary').boundingBox();
    expect(summaryBox).toBeTruthy();
    expect(summaryBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-teknik-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(reviewPath);
  const publishToken = await page.locator('#news-publish-token').inputValue();
  const publishResponse = await page.request.post(`${reviewPath}/yayimla`, {
    form: { _token: publishToken },
  });
  expect(publishResponse.status()).toBeLessThan(500);
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(reviewPath);
    await expect(page.getByText('Haberi yazan, son düzenleyen veya incelemeye gönderen kişi yayımlayamaz.').first()).toBeVisible();
    await expect(page.locator('.admin-news-show-head .admin-status', { hasText: 'İncelemede' })).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-haber-detay-hata-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(draftPath);
  const techSummary = page.locator('details.admin-news-show-tech summary');
  await techSummary.focus();
  expect(await techSummary.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
  await expect(page.getByRole('heading', { level: 2, name: 'Haber içeriği' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Özet' })).toBeVisible();
  await assertClean(page);
});

async function assertAsideHeight(page: Page, width: number): Promise<void> {
  const mainBox = await page.locator('.admin-news-show-read').boundingBox();
  const asideBox = await page.locator('.admin-news-show-meta').boundingBox();
  expect(mainBox && asideBox).toBeTruthy();
  if (width >= 1024) {
    expect(asideBox!.height).toBeLessThan(mainBox!.height + 160);
  } else {
    expect(asideBox!.y).toBeGreaterThan(mainBox!.y);
  }
}

async function createDraft(page: Page, title: string, categoryLabel: string, summary: string, body: string): Promise<string> {
  await page.goto('/yonetim/haberler/yeni');
  await page.locator('#news-title').fill(title);
  await page.locator('#news-summary').fill(summary);
  await page.locator('#news-body').fill(body);
  await page.locator('#news-category').selectOption({ label: categoryLabel });
  await page.getByRole('button', { name: 'Taslağı kaydet' }).click();
  await page.waitForURL(/\/yonetim\/haberler\/[0-9a-fA-F-]+$/);

  return new URL(page.url()).pathname;
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
