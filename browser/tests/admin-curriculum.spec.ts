import path from 'node:path';
import { expect, test, type Locator, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const programName = 'Tarayici programi uzun kart basligi durum etiketiyle carpismaz ve dar ekranda alt satira iner';
const programCode = 'browser_prog_uzun_teknik_kod_satiri_tasmadan_sarilir';

test('curriculum program tree, create error, delete block, and moderator view', async ({ page, browser }, info) => {
  test.setTimeout(240_000);
  const data = manifest();
  const deniedContext = await browser.newContext();
  const denied = await deniedContext.newPage();
  installVideoAbort(denied);
  await watch(denied);
  await login(denied, data.users.moderator);
  const deniedResponse = await denied.goto(data.paths.curriculum);
  expect(deniedResponse?.status()).toBe(403);
  await deniedContext.close();

  const viewerContext = await browser.newContext();
  const viewer = await viewerContext.newPage();
  installVideoAbort(viewer);
  await watch(viewer);
  await login(viewer, data.users.curriculumModerator);
  await viewer.goto(data.paths.curriculum);
  await expect(viewer.getByRole('heading', { level: 1, name: 'Müfredat Yönetimi' })).toBeVisible();
  await expect(viewer.getByRole('link', { name: 'Yeni program', exact: true })).toHaveCount(0);
  await viewer.goto(data.paths.curriculumProgram);
  await expect(viewer.getByRole('button', { name: 'Programı yayınla' })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Programı sil' })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Üniteyi pasif yap' })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Programı pasif yap' })).toHaveCount(0);
  await expect(viewer.getByText('Alt kayıt, sınıf dersi, içerik veya soru bağlantısı olduğu için program silinemez.')).toBeVisible();
  await viewer.goto(data.paths.curriculum);
  await expect(viewer.getByRole('button', { name: 'İlkokulu pasif yap' })).toHaveCount(0);
  await viewerContext.close();

  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);
  await page.emulateMedia({ reducedMotion: 'reduce' });

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculum);
    await expect(page.getByRole('heading', { level: 1, name: 'Müfredat Yönetimi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Programlar', exact: true })).toHaveCount(0);
    if (viewport.width >= 1024) {
      await expect(page.getByRole('link', { name: 'Müfredat Yönetimi', exact: true }).first()).toBeVisible();
    }
    await page.getByRole('link', { name: 'İlkokul', exact: true }).click();
    await expect(page.getByRole('link', { name: '1. sınıf Aktif', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni program', exact: true })).toBeVisible();
    await expect(page.getByText('Sınıf düzeyi silinmez')).toBeVisible();
    await expect(page.getByText(programName)).toBeVisible();
    await expect(page.getByText('Tarayici taslak')).toBeVisible();
    await expect(page.getByText('Örnek program')).toHaveCount(0);
    const cellDisplay = await page.locator('.admin-curriculum-program-table tbody td').first().evaluate((node) => getComputedStyle(node).display);
    expect(cellDisplay).toBe(viewport.width >= 1024 ? 'table-cell' : 'block');
    const row = page.locator('.admin-curriculum-program-table tbody tr').filter({ hasText: programName });
    const details = row.locator('details.admin-curriculum-tech');
    const summary = details.locator('summary');
    const meta = row.locator('.admin-curriculum-program__meta');
    const cardStatus = row.locator('.admin-curriculum-program__card-status');
    if (viewport.width >= 1024) {
      await expect(meta).toBeHidden();
      await expect(cardStatus).toBeHidden();
      await expect(row.locator('.admin-curriculum-program__open')).toBeHidden();
      await expect(row.locator('.admin-curriculum-program__subject')).toBeVisible();
      await expect(row.locator('.admin-curriculum-program__status')).toHaveText('Yayında');
      await expect(row.locator('.admin-curriculum-program__action a')).toBeVisible();
      expect(await oneLine(row.locator('.admin-curriculum-program__status .admin-status'))).toBe(true);
      expect(await oneLine(row.locator('.admin-curriculum-program__action a'))).toBe(true);
      expect(await oneLine(row.locator('.admin-curriculum-program__grade'))).toBe(true);
    } else {
      await expect(meta).toHaveText('Tarayici dersi · 1. sınıf · 1.0');
      await expect(cardStatus).toHaveText('Yayında');
      await expect(row.locator('.admin-curriculum-program__status')).toBeHidden();
      await expect(row.locator('.admin-curriculum-program__action a')).toBeHidden();
      await expect(row.locator('.admin-curriculum-program__open')).toBeVisible();
      const nameBox = await row.locator('.admin-user-person__name').boundingBox();
      const statusBox = await cardStatus.boundingBox();
      expect(nameBox && statusBox && boxesOverlap(nameBox, statusBox)).toBe(false);
    }
    const closedBox = await details.evaluate((node) => {
      const style = getComputedStyle(node);
      return { height: node.getBoundingClientRect().height, border: style.borderTopWidth, background: style.backgroundColor };
    });
    expect(closedBox.border).toBe('0px');
    expect(['rgba(0, 0, 0, 0)', 'transparent']).toContain(closedBox.background);
    expect(closedBox.height).toBeLessThanOrEqual(64);
    await assertLayout(page);
    await capture(page, info, `admin-programlar-${viewport.name}.png`);
    await row.scrollIntoViewIfNeeded();
    await captureElement(row, info, `admin-program-kart-kapali-${viewport.name}.png`);
    await summary.focus();
    await page.keyboard.press('Tab');
    await expect(page.locator(':focus')).toHaveText(/İncele/);
    expect(await page.locator(':focus').evaluate((node) => getComputedStyle(node).outlineWidth)).not.toBe('0px');
    await page.keyboard.press('Shift+Tab');
    await expect(summary).toBeFocused();
    expect(await summary.evaluate((node) => getComputedStyle(node).outlineWidth)).not.toBe('0px');
    if (viewport.width < 1024) {
      const textRight = await summary.evaluate((node) => {
        const range = document.createRange();
        range.selectNodeContents(node);
        return range.getBoundingClientRect().right;
      });
      const linkBox = await row.locator('.admin-curriculum-program__open').boundingBox();
      expect(linkBox && textRight <= linkBox.x + 1).toBe(true);
    }
    await page.keyboard.press('Enter');
    await expect(details).toHaveJSProperty('open', true);
    await expect(row.locator('code')).toHaveText(programCode);
    const codeFit = await row.locator('code').evaluate((node) => ({
      fits: node.scrollWidth <= node.clientWidth + 1,
      font: getComputedStyle(node).fontFamily.toLowerCase(),
    }));
    expect(codeFit.fits).toBe(true);
    expect(codeFit.font).toMatch(/mono|menlo|consolas/);
    const panel = row.locator('.admin-curriculum-tech__panel');
    await expect(panel).toBeVisible();
    const panelStyle = await panel.evaluate((node) => {
      const style = getComputedStyle(node);
      return { background: style.backgroundColor, border: style.borderTopWidth };
    });
    expect(panelStyle.background).toBe('rgb(247, 250, 252)');
    expect(panelStyle.border).toBe('1px');
    const openHeight = await details.evaluate((node) => node.getBoundingClientRect().height);
    expect(openHeight).toBeGreaterThan(closedBox.height + 24);
    const nameFit = await row.locator('.admin-user-person__name').evaluate((node) => node.scrollWidth <= node.clientWidth + 1);
    expect(nameFit).toBe(true);
    await assertLayout(page);
    await captureElement(row, info, `admin-program-kart-acik-${viewport.name}.png`);
    await page.keyboard.press('Enter');
    await expect(details).toHaveJSProperty('open', false);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculumNew);
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni program' })).toBeVisible();
    await expect(page.getByText('Program taslak oluşur. Bu form programı yayınlamaz.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Dersi pasif yap' })).toBeHidden();
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns).toBe(viewport.width >= 1024 ? 2 : 1);
    await assertLayout(page);
    await capture(page, info, `admin-program-yeni-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.curriculumNew);
  await page.locator('form').filter({ has: page.locator('#admin_curriculum_program_create_code') }).getByRole('button', { name: 'Taslak programı oluştur' }).click();
  await expect(page.getByText('Kod gerekli.')).toBeVisible();
  await expect(page.getByText('Ad gerekli.')).toBeVisible();
  await expect(page.getByText('Sürüm gerekli.')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-program-yeni-hata-1440.png');
  await page.getByRole('button', { name: 'İşlemler', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Dersi pasif yap' })).toBeVisible();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculumProgram);
    await expect(page.getByRole('heading', { level: 1, name: 'Tarayici programi' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Program ve kazanımlar' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Öğrenci kataloğu' })).toBeVisible();
    await expect(page.getByText('Katalog yayını programın yayınını değiştirmez.')).toBeVisible();
    await expect(page.getByText('Üst program nedeniyle öğrenciye kapalı')).toHaveCount(0);
    await expect(page.getByText('Emekliye ayırma da geri açılmaz.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Üniteyi pasif yap' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Programı pasif yap' })).toBeHidden();
    await expect(page.getByText('Alt kayıt, sınıf dersi, içerik veya soru bağlantısı olduğu için program silinemez.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programı sil' })).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-program-detay-${viewport.name}.png`);
  }

  await page.goto(data.paths.curriculumDraft);
  await expect(page.getByText('Program taslak. Alt kayıtlar kendi durumunu korur')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Programı sil' })).toBeHidden();
  await page.getByRole('button', { name: 'Yayın ve kalıcı işlemler' }).click();
  await expect(page.getByRole('button', { name: 'Programı sil' })).toBeVisible();
  await expect(page.getByText('Bu programda ünite yok.')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-program-taslak-1440.png');

  await page.goto(data.paths.curriculumProgram);
  await page.getByRole('button', { name: 'İşlemler', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Programı pasif yap' })).toBeVisible();
  const unit = page.getByRole('button', { name: /Tarayici unite/ });
  await unit.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: /Tarayici konu/ })).toBeVisible();
  await page.getByRole('button', { name: /Tarayici konu/ }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Tarayici kazanimi')).toBeVisible();
  await page.getByRole('button', { name: 'İşlemler', exact: true }).nth(1).click();
  await expect(page.getByRole('button', { name: 'Üniteyi pasif yap' })).toBeVisible();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculumProgram);
    await page.getByRole('button', { name: /Tarayici unite/ }).click();
    await page.getByRole('button', { name: /Tarayici konu/ }).click();
    await expect(page.getByText('Tarayici kazanimi')).toBeVisible();
    await page.evaluate(() => window.scrollTo(0, 0));
    await assertLayout(page);
    await capture(page, info, `admin-program-agac-${viewport.name}.png`);
    await page.goto('/yonetim/mufredat');
    await expect(page.getByRole('heading', { level: 1, name: 'Müfredat kataloğu' })).toBeVisible();
    await expect(page.locator('.admin-curriculum-page').getByRole('link', { name: 'Müfredat Yönetimi' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Detay' }).first()).toBeVisible();
    await assertLayout(page);
    await capture(page, info, `admin-mufredat-${viewport.name}.png`);
  }

  await page.goto(data.paths.curriculum);
  await page.getByRole('link', { name: 'İlkokul', exact: true }).click();
  await expect(page.getByRole('link', { name: '5. sınıf Aktif', exact: true })).toHaveCount(0);
  const stageHeights = async () => page.locator('.admin-curriculum-stage').evaluateAll((nodes) => nodes.map((node) => Math.round(node.getBoundingClientRect().height)));
  for (const viewport of [
    { name: '1440', width: 1440, height: 900 },
    { name: '768', width: 768, height: 1024 },
    { name: '390', width: 390, height: 844 },
  ] as const) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(`${data.paths.curriculum}?sinif=1`);
    await expect(page.locator('a.admin-curriculum-grade.is-selected')).toHaveText(/1\. sınıf/);
    await expect(page.getByRole('heading', { level: 2, name: 'Öğrenci kataloğu' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Matematik' })).toBeVisible();
    await expect(page.getByText('Taslak Ders')).toBeVisible();
    await expect(page.getByText('Tarayici programi')).toBeVisible();
    await expect(page.getByText('Tarayici taslak')).toHaveCount(0);
    const before = await stageHeights();
    expect(Math.max(...before) - Math.min(...before)).toBeLessThanOrEqual(4);
    await page.evaluate(() => window.scrollTo(0, 0));
    await assertLayout(page);
    await capture(page, info, `admin-sinif-1-${viewport.name}.png`);
    await page.getByRole('button', { name: 'İşlemler', exact: true }).click();
    const after = await stageHeights();
    expect(Math.max(...after) - Math.min(...after)).toBeLessThanOrEqual(4);
    await page.getByRole('button', { name: 'İşlemler', exact: true }).click();
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('link', { name: '2. sınıf Aktif', exact: true }).click();
  await expect(page.locator('a.admin-curriculum-grade.is-selected')).toHaveText(/2\. sınıf/);
  await expect(page.getByText('Tarayici taslak')).toBeVisible();
  await expect(page.getByText('Tarayici programi')).toHaveCount(0);
  await expect(page.getByText('Matematik')).toHaveCount(0);
  await expect(page.getByText('Bu sınıf düzeyinde öğrenci kataloğu kaydı yok.')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-sinif-2-1440.png');
  await page.getByRole('link', { name: '3. sınıf Aktif', exact: true }).click();
  await expect(page.locator('a.admin-curriculum-grade.is-selected')).toHaveText(/3\. sınıf/);
  await expect(page.getByRole('heading', { name: 'Kayıt yok' })).toBeVisible();
  await expect(page.getByText('Bu sınıf düzeyinde program yok.')).toBeVisible();
  await expect(page.getByText('Bu sınıf düzeyinde öğrenci kataloğu kaydı yok.')).toBeVisible();
  await expect(page.getByText('Tarayici programi')).toHaveCount(0);
  await expect(page.getByText('Tarayici taslak')).toHaveCount(0);
  await assertLayout(page);
  await capture(page, info, 'admin-sinif-3-1440.png');
  await page.getByRole('link', { name: '1. sınıf Aktif', exact: true }).click();
  await page.getByRole('link', { name: /İncele/ }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'Tarayici programi' })).toBeVisible();
  await page.getByRole('link', { name: 'Listeye dön' }).click();
  await expect(page).toHaveURL(/sinif=1/);
  await expect(page.locator('a.admin-curriculum-grade.is-selected')).toHaveText(/1\. sınıf/);
  await expect(page.getByText('Tarayici taslak')).toHaveCount(0);
  await page.getByRole('link', { name: 'Matematik' }).click();
  await page.getByRole('link', { name: 'Müfredat Yönetimine dön' }).click();
  await expect(page).toHaveURL(/sinif=1/);
  await expect(page.getByText('Tarayici programi')).toBeVisible();
  const notes = (page as Page & { __notes?: string[] }).__notes ?? [];
  let ignoredValidationResponses = 0;
  (page as Page & { __notes?: string[] }).__notes = notes.filter((note) => {
    if (ignoredValidationResponses < 1 && note.includes('status of 422')) {
      ignoredValidationResponses += 1;

      return false;
    }

    return true;
  });
  expect(ignoredValidationResponses).toBe(1);
  await assertClean(page);
});

async function oneLine(locator: Locator): Promise<boolean> {
  return locator.evaluate((node) => {
    const range = document.createRange();
    range.selectNodeContents(node);
    const rects = [...range.getClientRects()];
    const top = rects[0]?.top ?? 0;

    return rects.length > 0 && rects.every((rect) => Math.abs(rect.top - top) < 3);
  });
}

function boxesOverlap(a: { x: number; y: number; width: number; height: number }, b: { x: number; y: number; width: number; height: number }): boolean {
  return a.x < b.x + b.width - 1 && a.x + a.width > b.x + 1 && a.y < b.y + b.height - 1 && a.y + a.height > b.y + 1;
}

async function captureElement(locator: Locator, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const file = path.join('artifacts', 'screenshots', name);
  await locator.screenshot({ path: file });
  await info.attach(name, { path: file, contentType: 'image/png' });
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
