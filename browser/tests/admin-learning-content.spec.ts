import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const draftTitle = 'Duzenlenen anlatim uzun baslik durum etiketiyle carpismaz ve dar ekranda kendi alaninda sarilir';
const draftCode = 'browser_draft_uzun_teknik_kod_satiri_tasmadan_sarilir';

test('learning content list, editor, detail, and form error', async ({ page, browser }, info) => {
  test.setTimeout(240_000);
  const data = manifest();

  const teacherContext = await browser.newContext();
  const teacher = await teacherContext.newPage();
  installVideoAbort(teacher);
  await watch(teacher);
  await login(teacher, data.users.teacher);
  await teacher.goto('/yonetim/icerikler');
  await expect(teacher.getByRole('heading', { level: 1, name: 'Öğrenme İçerikleri' })).toBeVisible();
  await expect(teacher.getByText(draftTitle)).toBeVisible();
  await expect(teacher.getByText('Gizli admin taslagi')).toHaveCount(0);
  await teacherContext.close();

  const moderatorContext = await browser.newContext();
  const moderator = await moderatorContext.newPage();
  installVideoAbort(moderator);
  await watch(moderator);
  await login(moderator, data.users.moderator);
  await moderator.goto('/yonetim/icerikler');
  await expect(moderator.getByRole('heading', { level: 1, name: 'Öğrenme İçerikleri' })).toBeVisible();
  await expect(moderator.getByRole('link', { name: 'Yeni içerik', exact: true })).toHaveCount(0);
  await moderator.goto(data.paths.reviewDetail);
  await expect(moderator.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await moderatorContext.close();

  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/icerikler');
    await expect(page.getByRole('heading', { level: 1, name: 'Öğrenme İçerikleri' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni içerik', exact: true })).toBeVisible();
    await expect(page.locator('[data-content-summary="total"]')).toBeVisible();
    await expect(page.getByText(draftTitle).first()).toBeVisible();
    await expect(page.getByText('Toplama anlatimi').first()).toBeVisible();
    const row = page.locator('tr', { hasText: draftTitle });
    const published = page.locator('tr', { hasText: 'Toplama anlatimi' }).first();
    if (viewport.width >= 1024) {
      await expect.poll(async () => published.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('table-cell');
      expect(await oneLine(published.locator('.admin-learning-content__status .admin-status'))).toBeTruthy();
      expect(await oneLine(published.locator('.admin-learning-content__action a'))).toBeTruthy();
      expect(await oneLine(published.locator('.admin-learning-content__relation'))).toBeTruthy();
      const subjectHeader = page.locator('.admin-learning-content-table thead th').nth(2);
      await expect(subjectHeader).toBeVisible();
      await expect(subjectHeader).toHaveText('Ders ve sınıf');
      expect(await oneLine(subjectHeader)).toBeTruthy();
    } else {
      await expect.poll(async () => row.locator('td').first().evaluate((cell) => getComputedStyle(cell).display)).toBe('block');
      const nameBox = await row.locator('.admin-learning-content__name').boundingBox();
      const badgeBox = await row.locator('.admin-learning-content__card-status').boundingBox();
      expect(nameBox).not.toBeNull();
      expect(badgeBox).not.toBeNull();
      expect(boxesOverlap(nameBox!, badgeBox!)).toBeFalsy();
      const action = row.locator('.admin-learning-content__open');
      const actionBox = await action.boundingBox();
      expect(actionBox).not.toBeNull();
      expect(actionBox!.height).toBeGreaterThanOrEqual(44);
      expect(actionBox!.width).toBeGreaterThanOrEqual(44);
    }
    await row.locator('summary').focus();
    await page.keyboard.press('Enter');
    const code = row.locator('code', { hasText: draftCode });
    await expect(code).toBeVisible();
    const codeFit = await code.evaluate((node) => node.scrollWidth <= node.clientWidth + 1);
    expect(codeFit).toBeTruthy();
    const panel = await row.locator('.admin-learning-content-tech__panel').evaluate((node) => {
      const style = getComputedStyle(node);
      return { background: style.backgroundColor, border: style.borderTopWidth };
    });
    expect(panel.background).toBe('rgb(247, 250, 252)');
    expect(panel.border).toBe('1px');
    await page.keyboard.press('Enter');
    await assertLayout(page);
    await capture(page, info, `admin-icerikler-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/yonetim/icerikler?q=zzzz-yok-icerik');
  await expect(page.getByText('Kayıt yok')).toBeVisible();
  await expect(page.locator('[data-content-summary="total"]')).not.toHaveText('0');
  await assertLayout(page);
  await capture(page, info, 'admin-icerikler-bos-1440.png');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/yonetim/icerikler?q=zzzz-yok-icerik');
  await expect(page.getByText('Kayıt yok')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-icerikler-bos-390.png');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.reviewDetail);
    await expect(page.getByRole('heading', { level: 1, name: 'Yayin bekleyen anlatim' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Yayımla' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Ders, program ve kazanım' })).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-icerik-detay-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.revision);
    await expect(page.getByRole('heading', { level: 1, name: draftTitle })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Kaydet' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Yeni blok ekle' })).toBeVisible();
    await expect(page.getByText('Taslak ilk paragraf')).toBeVisible();
    await expect(page.getByText('Taslak ikinci paragraf')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
    const save = page.getByRole('button', { name: 'Kaydet' });
    const saveBox = await save.boundingBox();
    expect(saveBox).not.toBeNull();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-icerik-editor-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/icerikler/yeni');
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni içerik' })).toBeVisible();
    await page.locator('#learning_content_create_code').evaluate((node) => node.removeAttribute('required'));
    await page.locator('#learning_content_create_subjectId').evaluate((node) => node.removeAttribute('required'));
    await page.locator('#learning_content_create_learningOutcomeId').evaluate((node) => node.removeAttribute('required'));
    await page.getByLabel('Başlık').fill('Korunan baslik');
    await page.getByRole('button', { name: 'Taslak oluştur' }).click();
    await expect(page.getByText('Kod zorunludur.')).toBeVisible();
    await expect(page.getByLabel('Başlık')).toHaveValue('Korunan baslik');
    await assertLayout(page);
    await capture(page, info, `admin-icerik-yeni-hata-${viewport.name}.png`);
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
