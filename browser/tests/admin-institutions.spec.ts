import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('superadmin institution list, create and detail follow the reference', async ({ page }, info) => {
  const data = manifest();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);
  await page.emulateMedia({ reducedMotion: 'reduce' });

  let listMembers = '';
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.institutions);
    await expect(page.getByRole('heading', { level: 1, name: 'Kurumlar' })).toBeVisible();
    await expect(page.locator('a.admin-institution-primary')).toHaveText('+ Yeni kurum');
    await expect(page.locator('[data-institution-summary]')).toHaveCount(4);
    await expect(page.getByPlaceholder('Kurum adı veya slug')).toBeVisible();
    await expect(page.getByText('Uzun Adli Bekleyen Kurs Merkezi')).toBeVisible();
    await expect(page.getByText('Tarayici Okulu')).toBeVisible();
    await expect(page.getByText('Görsel tasarım örneği')).toHaveCount(0);
    const schoolMembers = (await page.locator('tr', { hasText: 'Tarayici Okulu' }).locator('td[data-label="Aktif üye"]').innerText()).trim();
    if (listMembers === '') {
      listMembers = schoolMembers;
    }
    expect(schoolMembers).toBe(listMembers);
    const cellDisplay = await page.locator('.admin-user-table tbody td').first().evaluate((node) => getComputedStyle(node).display);
    expect(cellDisplay).toBe(viewport.width >= 1024 ? 'table-cell' : 'block');
    const filterButton = page.locator('form[aria-label="Kurumları filtrele"]').getByRole('button', { name: 'Filtrele', exact: true });
    const filterBox = await filterButton.boundingBox();
    expect(filterBox).not.toBeNull();
    expect(filterBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-kurumlar-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.institutions);
  const totals = await readSummary(page);
  expect(Number(totals.pending)).toBeGreaterThanOrEqual(1);
  expect(Number(totals.active)).toBeGreaterThanOrEqual(1);
  const filters = page.locator('form[aria-label="Kurumları filtrele"]');
  await filters.locator('#institution-filter-q').fill('olmayan-kurum-slug');
  await filters.getByRole('button', { name: 'Filtrele', exact: true }).click();
  await expect(page.getByText('Filtrelere uygun kurum bulunamadı.')).toBeVisible();
  expect(await readSummary(page)).toEqual(totals);
  await filters.getByRole('link', { name: 'Temizle', exact: true }).click();
  await expect(page).toHaveURL(/\/yonetim\/kurumlar$/);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.institutionNew);
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni kurum' })).toBeVisible();
    await expect(page.getByText('Yeni kurum beklemede oluşur. Bu form kurumu aktif yapmaz.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Listeye dön' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'İptal', exact: true })).toBeVisible();
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    const searchBox = await page.locator('#institution-owner-search').boundingBox();
    expect(searchBox).not.toBeNull();
    expect(searchBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-kurum-yeni-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.institutionNew);
  const search = page.locator('#institution-owner-search');
  await search.fill('Sahip');
  const option = page.getByRole('option', { name: /Deneme Sahip/ });
  await expect(option).toBeVisible();
  await search.press('Enter');
  await expect(page.locator('[data-owner-label]')).toContainText('Deneme Sahip');
  await expect(page.locator('[data-owner-value]')).not.toHaveValue('');
  await page.getByRole('button', { name: 'Seçimi temizle' }).click();
  await expect(page.locator('[data-owner-value]')).toHaveValue('');
  await search.fill('zzzzzzyok');
  await expect(page.getByText('Sonuç bulunamadı.')).toBeVisible();
  await page.getByRole('button', { name: 'Kurumu oluştur', exact: true }).click();
  await expect(page.getByText('Kurum adı gerekli.')).toBeVisible();
  await expect(page.getByText('Kurum türü gerekli.')).toBeVisible();
  await expect(page.getByText('İlk sahip hesabı gerekli.')).toBeVisible();
  await expect(page.getByText('İşlem gerekçesi gerekli.')).toBeVisible();
  await expect(page.getByText('Kurum oluşturmayı onaylamanız gerekir.')).toBeVisible();
  await page.locator('#admin_institution_create_name').fill('Tarayici Gecici Kurum');
  await page.getByRole('button', { name: 'Kurumu oluştur', exact: true }).click();
  await expect(page.locator('#admin_institution_create_name')).toHaveValue('Tarayici Gecici Kurum');
  await assertLayout(page);
  await capture(page, info, 'admin-kurum-yeni-hata-1440.png');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.institution);
    await expect(page.getByRole('heading', { level: 1, name: 'Kurum detayı' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tarayici Okulu' })).toBeVisible();
    await expect(page.locator('[data-institution-members]')).toHaveText(listMembers);
    await expect(page.locator('[data-institution-owners]')).toHaveText('1');
    await expect(page.getByText('Türkçe')).toBeVisible();
    await expect(page.getByText('Europe/Istanbul')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Üyeler', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'İşlem geçmişi', exact: true })).toBeVisible();
    await expect(page.locator('form[name="admin_institution_status"]')).toBeVisible();
    const actions = await page.locator('#admin_institution_status_action option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
    expect(actions).toContain('suspend');
    expect(actions).toContain('archive');
    expect(actions).not.toContain('activate');
    const columns = await page.locator('.admin-institution-detail-grid').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    const saveBox = await page.getByRole('button', { name: 'Durumu kaydet' }).boundingBox();
    expect(saveBox).not.toBeNull();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-kurum-detay-${viewport.name}.png`);
  }

  const notes = (page as Page & { __notes?: string[] }).__notes ?? [];
  let ignoredValidationResponses = 0;
  (page as Page & { __notes?: string[] }).__notes = notes.filter((note) => {
    if (ignoredValidationResponses < 2 && note.includes('status of 422')) {
      ignoredValidationResponses += 1;

      return false;
    }

    return true;
  });
  expect(ignoredValidationResponses).toBe(2);
  await assertClean(page);
});

async function readSummary(page: Page): Promise<Record<string, string>> {
  return page.locator('[data-institution-summary]').evaluateAll((nodes) => {
    const values: Record<string, string> = {};
    for (const node of nodes) {
      const key = node.getAttribute('data-institution-summary') ?? '';
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
