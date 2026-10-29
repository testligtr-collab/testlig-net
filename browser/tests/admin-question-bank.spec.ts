import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const longStem = 'Editor sorusu uzun metin durum etiketiyle carpismaz ve dar ekranda kendi alaninda sarilir.';

test('question bank list, detail, editor, and form error', async ({ page, browser }, info) => {
  test.setTimeout(240_000);
  const data = manifest();

  const moderatorContext = await browser.newContext();
  const moderator = await moderatorContext.newPage();
  installVideoAbort(moderator);
  await watch(moderator);
  await login(moderator, data.users.moderator);
  await moderator.goto('/yonetim/sorular');
  await expect(moderator.getByRole('heading', { level: 1, name: 'Soru Havuzu' })).toBeVisible();
  await expect(moderator.getByRole('link', { name: 'Yeni soru', exact: true })).toHaveCount(0);
  await expect(moderator.getByRole('link', { name: 'CSV ile içe aktar' })).toHaveCount(0);
  const denied = await moderator.request.post('/yonetim/sorular/yeni', { form: { stem: 'izinsiz' } });
  expect(denied.status()).toBe(403);
  await moderator.goto(data.paths.questionReview);
  await expect(moderator.getByRole('button', { name: 'Yayınla' })).toHaveCount(0);
  await expect(moderator.getByText('correctStableKey')).toHaveCount(0);
  await moderatorContext.close();

  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, data.users.admin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/sorular');
    await expect(page.getByRole('heading', { level: 1, name: 'Soru Havuzu' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni soru', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'CSV ile içe aktar' })).toBeVisible();
    await expect(page.locator('[data-question-summary="total"]')).toBeVisible();
    await expect(page.getByText(longStem).first()).toBeVisible();
    await expect(page.getByText('Iki arti iki kactir').first()).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    const row = page.locator('tr', { hasText: longStem });
    const published = page.locator('tr', { hasText: 'Iki arti iki kactir' }).first();
    if (viewport.width >= 1024) {
      await expect.poll(async () => published.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      expect(await oneLine(published.locator('.admin-question__status .admin-status'))).toBeTruthy();
      expect(await oneLine(published.locator('.admin-question__action a'))).toBeTruthy();
      expect(await oneLine(published.locator('.admin-question__relation'))).toBeTruthy();
      const statusBox = await published.locator('.admin-question__status').boundingBox();
      const actionBox = await published.locator('.admin-question__action a').boundingBox();
      expect(statusBox && actionBox).toBeTruthy();
      const mid = (box: { y: number; height: number }) => box.y + box.height / 2;
      expect(Math.abs(mid(statusBox!) - mid(actionBox!))).toBeLessThan(8);
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const nameBox = await row.locator('.admin-question__name').boundingBox();
      const badgeBox = await row.locator('.admin-question__card-status').boundingBox();
      const actionBox = await row.locator('.admin-question__open').boundingBox();
      expect(nameBox && badgeBox && actionBox).toBeTruthy();
      expect(boxesOverlap(nameBox!, badgeBox!)).toBeFalsy();
      expect(boxesOverlap(nameBox!, actionBox!)).toBeFalsy();
      expect(actionBox!.height).toBeGreaterThanOrEqual(44);
    }
    await assertLayout(page);
    await capture(page, info, `admin-sorular-${viewport.name}.png`);
    if (viewport.name === '1440') {
      const summary = row.locator('summary');
      await summary.focus();
      expect(await summary.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
      await summary.click();
      const code = row.locator('code').first();
      const box = await code.boundingBox();
      expect(box).toBeTruthy();
      expect(box!.width).toBeGreaterThan(box!.height);
      expect(box!.width).toBeGreaterThan(40);
      await capture(page, info, 'admin-sorular-teknik-1440.png');
    }
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/sorular?q=bu-filtre-bos-sonuc');
  await expect(page.getByText('Bu filtreye uyan soru yok.')).toBeVisible();
  await expect(page.locator('[data-question-summary="total"]')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-sorular-bos-1440.png');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.questionPublished);
    await expect(page.getByRole('heading', { level: 1, name: 'Iki arti iki kactir' })).toBeVisible();
    await expect(page.locator('.admin-question-statusline')).toContainText('Sürüm #');
    await expect(page.getByText('Doğru cevap')).toBeVisible();
    await expect(page.getByText('Dort')).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await expect(page.getByText('Soru yayını, teste eklenme ve öğrenciye sunulma ayrı kurallardır.')).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-soru-yayin-${viewport.name}.png`);
  }

  const draftDetail = data.paths.questionEdit.replace(/\/duzenle$/, '');
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(draftDetail);
    await expect(page.getByRole('heading', { level: 1 })).toContainText(longStem);
    await expect(page.locator('.admin-question-preview')).toContainText(longStem);
    await expect(page.getByText('Doğru cevap')).toBeVisible();
    expect(await page.locator('.admin-question-stage').evaluate((node) => getComputedStyle(node).alignItems)).toBe('start');
    expect(await page.locator('.admin-question-side').evaluate((node) => getComputedStyle(node).alignSelf)).toBe('start');
    await assertLayout(page);
    await capture(page, info, `admin-soru-taslak-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${data.paths.questionPublished}/gorunum`);
  await expect(page.getByRole('heading', { level: 1, name: 'Önizleme' })).toBeVisible();
  await expect(page.getByText('Doğru cevap')).toHaveCount(0);
  await expect(page.getByText('Dort')).toBeVisible();
  await expect(page.getByText('correctStableKey')).toHaveCount(0);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.questionReview);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Inceleme sorusu koku');
    await expect(page.locator('.admin-question-preview')).toContainText('Inceleme sorusu koku');
    await expect(page.getByRole('button', { name: 'Yayınla' })).toBeVisible();
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-soru-inceleme-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.questionEdit);
    await expect(page.getByRole('heading', { level: 1, name: 'Soruyu düzenle' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'İncelemeye gönder' })).toBeVisible();
    await expect(page.locator('.option-row')).toHaveCount(2);
    await expect(page.getByText('correctStableKey')).toHaveCount(0);
    if (viewport.name === '1440') {
      const stem = page.locator('#question-stem');
      await stem.focus();
      expect(await stem.evaluate((node) => getComputedStyle(node).outlineStyle)).not.toBe('none');
      const before = await page.locator('.option-row').count();
      await page.locator('#add-option').click();
      await expect(page.locator('.option-row')).toHaveCount(before + 1);
      await page.locator('[data-remove-option]').last().click();
      await expect(page.locator('.option-row')).toHaveCount(before);
    }
    await page.evaluate(() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }));
    await assertLayout(page);
    await capture(page, info, `admin-soru-editor-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/sorular/yeni');
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni soru' })).toBeVisible();
    await page.locator('#question-stem').evaluate((node) => node.removeAttribute('required'));
    await page.locator('#question-outcome').evaluate((node) => node.removeAttribute('required'));
    await page.locator('textarea[name="options[]"]').nth(0).fill('Bir');
    await page.locator('textarea[name="options[]"]').nth(1).fill('Iki');
    await page.locator('#question-explanation').fill('Korunan cozum');
    await page.locator('#question-stem').fill('');
    await page.getByRole('button', { name: 'Taslak kaydet' }).click();
    await expect(page.getByText('Soru metni gerekli.')).toBeVisible();
    await expect(page.locator('#question-explanation')).toHaveValue('Korunan cozum');
    await assertLayout(page);
    await capture(page, info, `admin-soru-yeni-hata-${viewport.name}.png`);
  }

  const notes = (page as Page & { __notes?: string[] }).__notes ?? [];
  let ignoredValidationResponses = 0;
  const kept = notes.filter((note) => {
    if (note.includes('status of 422')) {
      ignoredValidationResponses += 1;

      return false;
    }

    return true;
  });
  expect(ignoredValidationResponses).toBe(4);
  (page as Page & { __notes?: string[] }).__notes = kept;
  await assertClean(page);
});

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
