import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, login, manifest, openMobile, shot, watch } from './support';

const HOMEWORK_VIEWPORTS = [
  { name: '1920', width: 1920, height: 1080 },
  { name: '1440', width: 1440, height: 900 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

async function gotoOk(page: Page, path: string): Promise<void> {
  const response = await page.goto(path);
  expect(response?.status(), path).toBe(200);
}

async function noFakeControls(page: Page): Promise<void> {
  await expect(page.locator('.hw-page').getByRole('button', { name: /Yayımla|Ata$|Gönder|Teslim|Değerlendir/ })).toHaveCount(0);
  await expect(page.locator('.hw-page').getByRole('link', { name: /Yayımla|Ata$|Gönder|Teslim et|Değerlendir/ })).toHaveCount(0);
}

async function touchTargets(page: Page): Promise<void> {
  const small = await page.evaluate(() => Array.from(document.querySelectorAll('.hw-page a.btn, .hw-page button, .hw-page input:not([type="hidden"]), .hw-page select, .hw-page textarea'))
    .filter((node) => {
      const box = node.getBoundingClientRect();
      return box.width > 0 && box.height > 0 && box.height < 44;
    })
    .map((node) => `${node.tagName.toLowerCase()}.${node.className} ${Math.round(node.getBoundingClientRect().height)}`));
  expect(small, 'interactive targets under 44px').toEqual([]);
}

async function screens(page: Page, info: TestInfo, route: string, path: string, options: { touch?: boolean } = {}): Promise<void> {
  for (const viewport of HOMEWORK_VIEWPORTS) {
    await page.setViewportSize(viewport);
    await gotoOk(page, path);
    await assertLayout(page);
    if (viewport.width <= 768 && options.touch !== false) {
      await touchTargets(page);
    }
    await shot(page, info, 'teacher-homework', route, viewport.name);
  }
}

test('teacher draft homework list, detail and edit screens at four widths', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);

  const list = await page.goto(data.paths.homeworkList);
  expect(list?.status()).toBe(200);
  expect(list?.headers()['cache-control'] ?? '').toContain('no-store');
  await expect(page.getByRole('heading', { level: 1, name: 'Ödevlerim' })).toBeVisible();
  await expect(page.locator('[data-testid="hw-draft-count"] strong')).toHaveText('2');
  await expect(page.getByText(data.paths.homeworkTitle, { exact: true })).toBeVisible();
  await expect(page.locator('.hw-badge--draft').first()).toHaveText('Taslak');
  await expect(page.getByRole('link', { name: 'Ödevlerim', exact: true }).first()).toBeVisible();
  await expect(page.getByText(data.paths.foreignTitle)).toHaveCount(0);
  await noFakeControls(page);
  await expect(page.getByText('Yayında')).toHaveCount(0);
  await screens(page, info, 'odevler', data.paths.homeworkList);

  // Desktop shows a table, narrow screens switch to labeled cards without horizontal scroll.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkList);
  await expect(page.locator('.hw-table thead')).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await gotoOk(page, data.paths.homeworkList);
  await expect(page.locator('.hw-table thead')).toBeHidden();
  await expect(page.locator('.hw-table td[data-label="Son teslim"]').first()).toBeVisible();

  // Empty search keeps the filter form and explains the empty result.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, `${data.paths.homeworkList}?q=bulunmayan-baslik`);
  await expect(page.getByRole('heading', { name: 'Aramana uygun taslak bulunamadı' })).toBeVisible();
  await expect(page.getByText('Henüz ödev taslağın yok')).toHaveCount(0);
  await screens(page, info, 'odevler-arama-bos', `${data.paths.homeworkList}?q=bulunmayan-baslik`);

  // Search narrows the list and keeps the real draft total visible.
  await gotoOk(page, `${data.paths.homeworkList}?q=kesirler`);
  await expect(page.locator('[data-testid="hw-draft-count"] strong')).toHaveText('1');
  await expect(page.locator('[data-testid="hw-draft-count"]')).toContainText('toplam 2');

  await page.goto(data.paths.homeworkList);
  await page.getByRole('link', { name: `İncele: ${data.paths.homeworkTitle}` }).click();
  await expect(page).toHaveURL(/\/ogretmen\/odevler\/[0-9a-f-]{36}$/);
  await expect(page.getByRole('heading', { level: 1, name: data.paths.homeworkTitle })).toBeVisible();
  await expect(page.getByText('Bu taslak öğrencilere atanmadı; öğrenciler göremez.')).toBeVisible();
  await expect(page.getByText('15.06.2099 18:30')).toBeVisible();
  await noFakeControls(page);
  await screens(page, info, 'odev-detay', data.paths.homeworkDetail);

  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkEdit);
  await expect(page.getByRole('heading', { level: 1, name: 'Taslağı düzenle' })).toBeVisible();
  await expect(page.getByLabel('Ödev başlığı')).toHaveValue(data.paths.homeworkTitle);
  await expect(page.getByLabel('Son teslim tarihi')).toHaveValue('2099-06-15');
  await expect(page.getByLabel('Son teslim saati')).toHaveValue('18:30');
  await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Vazgeç' })).toBeVisible();
  await expect(page.getByText('Yayımladıktan sonra ödev metni değiştirilemez.')).toBeVisible();
  await noFakeControls(page);
  await screens(page, info, 'odev-duzenle', data.paths.homeworkEdit);

  await assertClean(page);
});

test('teacher draft homework empty list and mobile menu entry', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.homeworkEmptyTeacher);

  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkList);
  await expect(page.locator('[data-testid="hw-draft-count"] strong')).toHaveText('0');
  await expect(page.getByRole('heading', { name: 'Henüz ödev taslağın yok' })).toBeVisible();
  await expect(page.getByText('Taslak kaydetmek öğrencilere atama yapmaz.').first()).toBeVisible();
  await expect(page.locator('.hw-table')).toHaveCount(0);
  await screens(page, info, 'odevler-bos', data.paths.homeworkList);

  // Teacher mobile drawer lives on /ogretmen/* (workspace dashboard uses a different chrome).
  await page.setViewportSize({ width: 390, height: 844 });
  await gotoOk(page, '/ogretmen/siniflarim');
  await openMobile(page);
  const mobileNav = page.locator('#teacher-mobile-nav');
  await expect(mobileNav).toBeVisible();
  await mobileNav.getByRole('link', { name: 'Ödevlerim', exact: true }).click();
  await expect(page).toHaveURL(/\/ogretmen\/odevler$/);
  await assertClean(page);
});

test('teacher draft homework form validates, counts Unicode and never writes on invalid input', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);

  await screens(page, info, 'odev-yeni', data.paths.homeworkNew);

  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkNew);
  await expect(page.getByRole('heading', { level: 1, name: 'Yeni ödev' })).toBeVisible();
  await expect(page.getByText('Europe/Istanbul', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('Taslak kaydetmek öğrencilere atama yapmaz.').first()).toBeVisible();
  await expect(page.getByText('Yayımladıktan sonra ödev metni değiştirilemez.')).toBeVisible();
  await noFakeControls(page);

  // The counter counts code points like the server (emoji = 1, not 2).
  const title = page.getByLabel('Ödev başlığı');
  await title.fill('😀😀 Çarşamba');
  await expect(page.locator('#hw-title-counter')).toHaveText('11 / 150');
  await title.fill('');
  await page.getByLabel('Ödev açıklaması').fill('Satır 1\nSatır 2');
  await expect(page.locator('#hw-description-counter')).toHaveText('15 / 10000');
  await page.getByLabel('Son teslim tarihi').fill('2099-01-05');
  await page.getByLabel('Son teslim saati').fill('09:15');
  await expect(page.locator('.hw-summary')).toContainText('05.01.2099 09:15');

  // Invalid submit: server keeps the input, shows field errors, writes nothing.
  await page.getByLabel('Ödev açıklaması').fill('Satır 1 <b>kalın</b>');
  await page.getByRole('button', { name: 'Taslak kaydet' }).click();
  await expect(page).toHaveURL(/\/ogretmen\/odevler\/yeni$/);
  await expect(page.locator('.hw-alert')).toContainText('Kaydedilemedi.');
  await expect(page.getByText('Ödev başlığı boş olamaz.')).toBeVisible();
  await expect(page.getByText('Ödev açıklaması HTML içeremez.')).toBeVisible();
  await expect(page.getByLabel('Ödev açıklaması')).toHaveValue('Satır 1 <b>kalın</b>');
  await expect(page.getByLabel('Son teslim tarihi')).toHaveValue('2099-01-05');
  await expect(page.getByLabel('Son teslim saati')).toHaveValue('09:15');
  await expect(page.getByLabel('Ödev başlığı')).toHaveAttribute('aria-invalid', 'true');
  for (const viewport of HOMEWORK_VIEWPORTS) {
    await page.setViewportSize(viewport);
    await assertLayout(page);
    await shot(page, info, 'teacher-homework', 'odev-gecersiz', viewport.name);
  }

  // Keyboard focus is visible on the primary action.
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Taslak kaydet' }).focus();
  const outline = await page.getByRole('button', { name: 'Taslak kaydet' }).evaluate((node) => getComputedStyle(node).outlineStyle);
  expect(outline).not.toBe('none');

  await gotoOk(page, data.paths.homeworkList);
  await expect(page.locator('[data-testid="hw-draft-count"] strong')).toHaveText('2');
  // Intentional 422 validation responses are expected above; clear watch notes before the clean check.
  const notes = (page as Page & { __notes?: string[] }).__notes;
  if (notes) {
    notes.length = 0;
  }
  await assertClean(page);
});

test('teacher draft homework long title and description wrap without overflow', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);

  await screens(page, info, 'odev-uzun-detay', data.paths.homeworkLongDetail, { touch: false });
  await screens(page, info, 'odev-uzun-duzenle', data.paths.homeworkLongEdit);

  await page.setViewportSize({ width: 390, height: 844 });
  await gotoOk(page, data.paths.homeworkList);
  await assertLayout(page);
  await shot(page, info, 'teacher-homework', 'odevler-uzun', '390');
  await gotoOk(page, data.paths.homeworkLongDetail);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
  await assertClean(page);
});

test('other roles and teachers without assignment do not see teacher draft homework', async ({ browser }) => {
  const data = manifest();
  for (const email of [data.users.student, data.users.owner, data.users.admin, data.users.superadmin, data.users.emptyTeacher]) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await watch(page);
    await login(page, email);
    const response = await page.goto(data.paths.homeworkList);
    expect([403, 404], email).toContain(response?.status());
    const detail = await page.goto(data.paths.homeworkDetail);
    expect(detail?.status(), email).toBe(404);
    await expect(page.getByText(data.paths.homeworkTitle)).toHaveCount(0);
    await context.close();
  }

  const anonymous = await browser.newContext();
  const page = await anonymous.newPage();
  await page.goto(data.paths.homeworkList);
  await expect(page).toHaveURL(/\/giris$/);
  await anonymous.close();
});
