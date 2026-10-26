import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('superadmin classroom list, create, detail and error follow the reference', async ({ page }, info) => {
  const data = manifest();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const institutionId = new URL(data.paths.classroomNew, 'http://127.0.0.1').searchParams.get('kurum') ?? '';
  const yearId = new URL(data.paths.classroomNew, 'http://127.0.0.1').searchParams.get('yil') ?? '';
  expect(institutionId).not.toBe('');
  expect(yearId).not.toBe('');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.classrooms);
    await expect(page.getByRole('heading', { level: 1, name: 'Sınıflar' })).toBeVisible();
    await expect(page.getByText('Kurum ve akademik yıl kapsamındaki sınıfları yönetin.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Yeni sınıf', exact: true })).toBeVisible();
    await expect(page.locator('[data-classroom-summary]')).toHaveCount(4);
    await expect(page.getByText('Öğretmen atanmamış', { exact: true }).first()).toBeVisible();
    await expect(page.locator('.admin-institution-note').filter({ hasText: 'Öğretmen atanmamış sayacı' })).toHaveText('Öğretmen atanmamış sayacı yalnız aktif sınıfları kapsar. Sonlanmış öğretmen ataması atanmış sayılmaz. Öğrenci sayısı aktif kayıtlardır. Sayaçlar seçili filtreden bağımsızdır.');
    await expect(page.getByPlaceholder('Sınıf adı veya kod')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Kurum' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Akademik yıl' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Durum' })).toBeVisible();
    await expect(page.locator('.admin-user-person__name', { hasText: 'Sinif A' })).toBeVisible();
    await expect(page.locator('tr', { hasText: 'Sinif A' })).toContainText('ve 1 öğretmen daha');
    await expect(page.locator('tr', { hasText: 'Eski Sinif' })).toContainText('Öğretmen atanmamış');
    await expect(page.locator('.admin-user-person__name', { hasText: 'Cok Uzun Adli Besinci Sinif Subesi' })).toBeVisible();
    await expect(page.locator('tr', { hasText: 'Cok Uzun Adli Besinci Sinif Subesi' })).toContainText('Uzun Adli Tarayici Egitim Kurumu');
    await expect(page.getByText('Örnek Eğitim Kurumu')).toHaveCount(0);
    await expect(page.getByText('Ayşe Yılmaz')).toHaveCount(0);
    await expect(page.getByText('Taslak')).toHaveCount(0);
    await expect(page.getByText('Görsel tasarım örneği')).toHaveCount(0);
    await expect(page.getByText('browser-student@example.test')).toHaveCount(0);
    const cellDisplay = await page.locator('.admin-user-table tbody td').first().evaluate((node) => getComputedStyle(node).display);
    expect(cellDisplay).toBe(viewport.width >= 1024 ? 'table-cell' : 'block');
    await expect(page.getByRole('link', { name: 'İncele', exact: true }).first()).toBeVisible();
    await expect(page.locator('a.admin-institution-detail').first()).toContainText('→');
    await expect(page.locator('.admin-institution-pager__current')).toHaveText('1');
    const filterButton = page.locator('form[aria-label="Sınıfları filtrele"]').getByRole('button', { name: 'Filtrele', exact: true });
    const filterBox = await filterButton.boundingBox();
    expect(filterBox).not.toBeNull();
    expect(filterBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-siniflar-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.classrooms);
  const totals = await readSummary(page);
  expect(Number(totals.active)).toBeGreaterThanOrEqual(1);
  expect(Number(totals.without)).toBeGreaterThanOrEqual(1);
  expect(Number(totals.institutions)).toBeGreaterThanOrEqual(1);
  const filters = page.locator('form[aria-label="Sınıfları filtrele"]');
  await filters.locator('#classroom-filter-q').fill('olmayan-sinif-adi');
  await filters.getByRole('button', { name: 'Filtrele', exact: true }).click();
  await expect(page.getByText('Filtrelere uygun kayıt bulunamadı.')).toBeVisible();
  expect(await readSummary(page)).toEqual(totals);
  await filters.getByRole('link', { name: 'Temizle', exact: true }).click();
  await expect(page).toHaveURL(/\/yonetim\/siniflar$/);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.classroomNew);
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni sınıf' })).toBeVisible();
    await expect(page.getByText('Yeni sınıf aktif oluşur. Kapalı akademik yıla sınıf eklenemez. Sınıf adı, müfredattaki sınıf düzeyi değildir.')).toBeVisible();
    await expect(page.locator('#admin_classroom_create_institutionId')).toHaveValue(institutionId);
    await expect(page.locator('#admin_classroom_create_academicYearId')).toHaveValue(yearId);
    await expect(page.getByRole('link', { name: 'Listeye dön' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'İptal', exact: true })).toBeVisible();
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    const headingIcon = await page.locator('#classroom-create-identity .admin-institution-heading-icon').boundingBox();
    expect(headingIcon).not.toBeNull();
    expect(headingIcon!.width).toBeGreaterThanOrEqual(40);
    expect(headingIcon!.height).toBeGreaterThanOrEqual(40);
    const fieldSkin = await page.locator('#admin_classroom_create_name').evaluate((node) => {
      const style = getComputedStyle(node);
      return { radius: style.borderRadius, border: style.borderTopWidth };
    });
    expect(fieldSkin.radius).toBe('10px');
    expect(fieldSkin.border).toBe('1px');
    await page.locator('#admin_classroom_create_name').focus();
    const outline = await page.locator('#admin_classroom_create_name').evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(outline).not.toBe('none');
    await page.locator('#admin_classroom_create_name').blur();
    const saveBox = await page.getByRole('button', { name: 'Sınıfı oluştur', exact: true }).boundingBox();
    expect(saveBox).not.toBeNull();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-sinif-yeni-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.classroomNew);
  await page.locator('#admin_classroom_create_name').fill('Tarayici Gecici Sinif');
  await page.locator('#admin_classroom_create_gradeLevel').selectOption('5');
  await page.getByRole('button', { name: 'Sınıfı oluştur', exact: true }).click();
  await expect(page.locator('#admin_classroom_create_name')).toHaveValue('Tarayici Gecici Sinif');
  await expect(page.locator('#admin_classroom_create_institutionId')).toHaveValue(institutionId);
  await expect(page.locator('#admin_classroom_create_gradeLevel')).toHaveValue('5');
  await expect(page.getByText('İşlem gerekçesi gerekli.', { exact: true })).toHaveCount(1);
  await expect(page.getByText('İşlem gerekçesi geçersiz.')).toHaveCount(0);
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await expect(page.locator('#admin_classroom_create_name')).toHaveValue('Tarayici Gecici Sinif');
    await assertLayout(page);
    await capture(page, info, `admin-sinif-yeni-hata-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.classroom);
    await expect(page.getByRole('heading', { level: 1, name: 'Sınıf detayı' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Sınıf bilgileri' })).toBeVisible();
    await expect(page.locator('section[aria-labelledby="classroom-facts"]')).toContainText('Sinif A');
    await expect(page.locator('section[aria-labelledby="classroom-facts"]')).toContainText('Tarayici Okulu');
    await expect(page.locator('section[aria-labelledby="classroom-teachers"]')).toContainText('Deneme Ogretmen');
    await expect(page.locator('section[aria-labelledby="classroom-teachers"]')).toContainText('Yardımcı öğretmen');
    await expect(page.locator('[data-classroom-students="active"]')).toHaveText('1');
    await expect(page.getByText('browser-student@example.test')).toHaveCount(0);
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    await assertLayout(page);
    await capture(page, info, `admin-sinif-detay-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.classroomLong);
    await expect(page.locator('section[aria-labelledby="classroom-facts"]')).toContainText('Cok Uzun Adli Besinci Sinif Subesi');
    await expect(page.locator('section[aria-labelledby="classroom-facts"]')).toContainText('Uzun Adli Tarayici Egitim Kurumu');
    await expect(page.locator('section[aria-labelledby="classroom-teachers"]')).toContainText('Öğretmen atanmamış');
    await expect(page.locator('[data-classroom-students="active"]')).toHaveText('0');
    await assertLayout(page);
    await capture(page, info, `admin-sinif-detay-bos-${viewport.name}.png`);
  }

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

async function readSummary(page: Page): Promise<Record<string, string>> {
  return page.locator('[data-classroom-summary]').evaluateAll((nodes) => {
    const values: Record<string, string> = {};
    for (const node of nodes) {
      const key = node.getAttribute('data-classroom-summary') ?? '';
      values[key] = (node.textContent ?? '').trim();
    }
    return values;
  });
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
