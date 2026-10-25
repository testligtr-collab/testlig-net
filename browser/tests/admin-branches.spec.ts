import path from 'node:path';
import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('superadmin branch list, create, detail and error follow the reference', async ({ page }, info) => {
  const data = manifest();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.superadmin);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const institutionId = new URL(data.paths.branchNew, 'http://127.0.0.1').searchParams.get('kurum') ?? '';
  expect(institutionId).not.toBe('');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.branches);
    await expect(page.getByRole('heading', { level: 1, name: 'Şube ve kampüsler' })).toBeVisible();
    await expect(page.getByText('Kurumlara bağlı şube ve kampüs kayıtlarını yönetin.')).toBeVisible();
    await expect(page.locator('a.admin-institution-primary')).toHaveText('+ Yeni şube / kampüs');
    await expect(page.locator('[data-branch-summary]')).toHaveCount(4);
    await expect(page.getByPlaceholder('Ad veya kod ile ara')).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Kurum' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Tür' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Durum' })).toBeVisible();
    await expect(page.getByText('Merkez Sube')).toBeVisible();
    await expect(page.getByText('MRK-01')).toBeVisible();
    await expect(page.getByText('Uzun Adli Bekleyen Kampus')).toBeVisible();
    await expect(page.getByText('UZUN-01')).toBeVisible();
    await expect(page.locator('tr', { hasText: 'Merkez Sube' })).toContainText('Tarayici Okulu');
    await expect(page.getByText('Örnek Eğitim Kurumu')).toHaveCount(0);
    await expect(page.getByText('Görsel tasarım örneği')).toHaveCount(0);
    const cellDisplay = await page.locator('.admin-user-table tbody td').first().evaluate((node) => getComputedStyle(node).display);
    expect(cellDisplay).toBe(viewport.width >= 1024 ? 'table-cell' : 'block');
    await expect(page.getByRole('link', { name: 'İncele', exact: true }).first()).toBeVisible();
    await expect(page.locator('a.admin-institution-detail').first()).toContainText('→');
    await expect(page.locator('.admin-institution-pager__current')).toHaveText('1');
    const filterButton = page.locator('form[aria-label="Şube ve kampüsleri filtrele"]').getByRole('button', { name: 'Filtrele', exact: true });
    const filterBox = await filterButton.boundingBox();
    expect(filterBox).not.toBeNull();
    expect(filterBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-subeler-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.branches);
  const totals = await readSummary(page);
  expect(Number(totals.active)).toBeGreaterThanOrEqual(1);
  expect(Number(totals.pending)).toBeGreaterThanOrEqual(1);
  expect(Number(totals.institutions)).toBeGreaterThanOrEqual(1);
  const filters = page.locator('form[aria-label="Şube ve kampüsleri filtrele"]');
  await filters.locator('#branch-filter-q').fill('olmayan-sube-kodu');
  await filters.getByRole('button', { name: 'Filtrele', exact: true }).click();
  await expect(page.getByText('Filtrelere uygun kayıt bulunamadı.')).toBeVisible();
  expect(await readSummary(page)).toEqual(totals);
  await filters.getByRole('link', { name: 'Temizle', exact: true }).click();
  await expect(page).toHaveURL(/\/yonetim\/subeler$/);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.branchNew);
    await expect(page.getByRole('heading', { level: 1, name: 'Yeni şube / kampüs' })).toBeVisible();
    await expect(page.getByText('Yeni kayıt beklemede oluşur. Bu form kaydı aktif yapmaz.')).toBeVisible();
    await expect(page.locator('#admin_branch_create_institutionId')).toHaveValue(institutionId);
    await expect(page.getByRole('link', { name: 'Listeye dön' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'İptal', exact: true })).toBeVisible();
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    const headingIcon = await page.locator('#branch-create-identity .admin-institution-heading-icon').boundingBox();
    expect(headingIcon).not.toBeNull();
    expect(headingIcon!.width).toBeGreaterThanOrEqual(40);
    expect(headingIcon!.height).toBeGreaterThanOrEqual(40);
    const fieldSkin = await page.locator('#admin_branch_create_name').evaluate((node) => {
      const style = getComputedStyle(node);
      return { radius: style.borderRadius, border: style.borderTopWidth };
    });
    expect(fieldSkin.radius).toBe('10px');
    expect(fieldSkin.border).toBe('1px');
    await page.locator('#admin_branch_create_name').focus();
    const outline = await page.locator('#admin_branch_create_name').evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(outline).not.toBe('none');
    await page.locator('#admin_branch_create_name').blur();
    const saveBox = await page.getByRole('button', { name: 'Kaydı oluştur', exact: true }).boundingBox();
    expect(saveBox).not.toBeNull();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-sube-yeni-${viewport.name}.png`);
  }

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(data.paths.branchNew);
  await page.locator('#admin_branch_create_name').fill('Tarayici Gecici Sube');
  await page.getByRole('button', { name: 'Kaydı oluştur', exact: true }).click();
  await expect(page.locator('#admin_branch_create_name')).toHaveValue('Tarayici Gecici Sube');
  await expect(page.locator('#admin_branch_create_institutionId')).toHaveValue(institutionId);
  await expect(page.getByText('Kurum içi kod gerekli.', { exact: true })).toHaveCount(1);
  await expect(page.getByText('İşlem gerekçesi gerekli.', { exact: true })).toHaveCount(1);
  await expect(page.getByText('İşlem gerekçesi geçersiz.')).toHaveCount(0);
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await expect(page.locator('#admin_branch_create_name')).toHaveValue('Tarayici Gecici Sube');
    await assertLayout(page);
    await capture(page, info, `admin-sube-yeni-hata-${viewport.name}.png`);
  }

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(data.paths.branch);
    await expect(page.getByRole('heading', { level: 1, name: 'Şube detayı' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Merkez Sube' })).toBeVisible();
    await expect(page.getByText('MRK-01').first()).toBeVisible();
    await expect(page.locator('section[aria-labelledby="branch-facts"]')).toContainText('Tarayici Okulu');
    await expect(page.locator('[data-branch-access]')).toHaveText('Erişim açık');
    await expect(page.locator('form[name="admin_branch_update"]')).toBeVisible();
    await expect(page.locator('form[name="admin_branch_status"]')).toBeVisible();
    const actions = await page.locator('#admin_branch_status_action option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
    expect(actions).toContain('suspend');
    expect(actions).toContain('archive');
    expect(actions).not.toContain('activate');
    const columns = await page.locator('.admin-institution-create').evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
    expect(columns, viewport.name).toBe(viewport.width >= 1024 ? 2 : 1);
    const saveBox = await page.getByRole('button', { name: 'Bilgileri kaydet' }).boundingBox();
    expect(saveBox).not.toBeNull();
    expect(saveBox!.height).toBeGreaterThanOrEqual(44);
    await assertLayout(page);
    await capture(page, info, `admin-sube-detay-${viewport.name}.png`);
  }

  await page.goto(data.paths.branchPending);
  await expect(page.locator('[data-branch-access]')).toHaveText('Erişim kapalı. Şube kaydı aktif değil.');
  const pendingActions = await page.locator('#admin_branch_status_action option').evaluateAll((nodes) => nodes.map((node) => (node as HTMLOptionElement).value));
  expect(pendingActions).toContain('activate');
  expect(pendingActions).toContain('archive');

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
  return page.locator('[data-branch-summary]').evaluateAll((nodes) => {
    const values: Record<string, string> = {};
    for (const node of nodes) {
      const key = node.getAttribute('data-branch-summary') ?? '';
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
