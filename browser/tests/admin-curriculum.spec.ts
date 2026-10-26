import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('curriculum program tree, create error, delete block, and moderator view', async ({ page, browser }, info) => {
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
  await expect(viewer.getByRole('heading', { level: 1, name: 'Programlar' })).toBeVisible();
  await expect(viewer.getByRole('link', { name: 'Yeni program', exact: true })).toHaveCount(0);
  await viewer.goto(data.paths.curriculumProgram);
  await expect(viewer.getByRole('button', { name: 'Programı yayınla' })).toHaveCount(0);
  await expect(viewer.getByRole('button', { name: 'Programı sil' })).toHaveCount(0);
  await expect(viewer.getByText('Alt kayıt, sınıf dersi, içerik veya soru bağlantısı olduğu için program silinemez.')).toBeVisible();
  await viewerContext.close();

  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);
  await page.emulateMedia({ reducedMotion: 'reduce' });

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculum);
    await expect(page.getByRole('heading', { level: 1, name: 'Programlar' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni program', exact: true })).toBeVisible();
    await expect(page.getByText('Tarayici programi')).toBeVisible();
    await expect(page.getByText('Tarayici taslak')).toBeVisible();
    await expect(page.getByText('Örnek program')).toHaveCount(0);
    const cellDisplay = await page.locator('.admin-user-table tbody td').first().evaluate((node) => getComputedStyle(node).display);
    expect(cellDisplay).toBe(viewport.width >= 1024 ? 'table-cell' : 'block');
    await assertLayout(page);
    await capture(page, info, `admin-programlar-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculumNew);
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni program' })).toBeVisible();
    await expect(page.getByText('Program taslak oluşur. Bu form programı yayınlamaz.')).toBeVisible();
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

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.curriculumProgram);
    await expect(page.getByRole('heading', { level: 1, name: 'Tarayici programi' })).toBeVisible();
    await expect(page.getByText('Üst program nedeniyle öğrenciye kapalı')).toHaveCount(0);
    await expect(page.getByText('Alt kayıt, sınıf dersi, içerik veya soru bağlantısı olduğu için program silinemez.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Programı sil' })).toHaveCount(0);
    await assertLayout(page);
    await capture(page, info, `admin-program-detay-${viewport.name}.png`);
  }

  await page.goto(data.paths.curriculumDraft);
  await expect(page.getByText('Program taslak. Alt kayıtlar kendi durumunu korur')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Programı sil' })).toBeVisible();
  await expect(page.getByText('Bu programda ünite yok.')).toBeVisible();
  await assertLayout(page);
  await capture(page, info, 'admin-program-taslak-1440.png');
  await assertClean(page);
});

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  const width = page.viewportSize()?.width ?? 1440;
  await page.setViewportSize({ width, height: Math.max(900, Math.min(height, 2400)) });
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
