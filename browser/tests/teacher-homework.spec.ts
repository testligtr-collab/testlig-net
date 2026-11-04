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
  await expect(page.locator('.hw-page').getByRole('button', { name: /Yayımla|^Ata$|Gönder|Teslim|Değerlendir/ })).toHaveCount(0);
  await expect(page.locator('.hw-page').getByRole('link', { name: /Yayımla|^Ata$|Gönder|Teslim et|Değerlendir/ })).toHaveCount(0);
}

async function expectCounts(page: Page, draft: string, assigned: string): Promise<void> {
  const counts = page.locator('[data-testid="hw-status-count"] strong');
  await expect(counts.nth(0)).toHaveText(draft);
  await expect(counts.nth(1)).toHaveText(assigned);
}

async function touchTargets(page: Page): Promise<void> {
  const small = await page.evaluate(() => Array.from(document.querySelectorAll('.hw-page a.btn, .hw-page button, .hw-page input:not([type="hidden"]), .hw-page select, .hw-page textarea'))
    .filter((node) => {
      const box = node.getBoundingClientRect();
      // The confirm checkbox is small by design; its whole label row is the touch target.
      const checkbox = node instanceof HTMLInputElement && node.type === 'checkbox';
      return !checkbox && box.width > 0 && box.height > 0 && box.height < 44;
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

/**
 * Unbroken long titles must wrap inside the homework content column.
 * Page-level scrollWidth alone is not enough (flex can overflow a child without growing html).
 */
async function assertHeadingFitsContent(page: Page, expectedTitle: string): Promise<void> {
  const metrics = await page.evaluate((title) => {
    const heading = document.getElementById('hw-heading');
    const content = document.querySelector('.hw-page');
    const head = heading?.closest('.hw-head');
    const textWrap = heading?.closest('.hw-head__text');
    const actions = document.querySelector('.hw-head__actions');
    if (
      !(heading instanceof HTMLElement)
      || !(content instanceof HTMLElement)
      || !(head instanceof HTMLElement)
      || !(textWrap instanceof HTMLElement)
    ) {
      return { ok: false as const, reason: 'missing-nodes' };
    }
    if ((heading.textContent ?? '').trim() !== title.trim()) {
      return { ok: false as const, reason: 'title-mismatch', actual: heading.textContent ?? '' };
    }
    const h = heading.getBoundingClientRect();
    const c = content.getBoundingClientRect();
    const a = actions instanceof HTMLElement ? actions.getBoundingClientRect() : null;
    const viewport = document.documentElement.clientWidth;
    return {
      ok: true as const,
      // Growing the element itself can hide overflow; compare against parent/viewport widths.
      headingVsParent: heading.scrollWidth - textWrap.clientWidth,
      headVsContent: head.scrollWidth - content.clientWidth,
      contentVsViewport: content.scrollWidth - viewport,
      pageScrollOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      headingWidth: h.width,
      contentWidth: c.width,
      headingTop: h.top,
      headingBottom: h.bottom,
      headingLeft: h.left,
      headingRight: h.right,
      contentLeft: c.left,
      contentRight: c.right,
      viewport,
      actionsTop: a?.top ?? null,
      actionsLeft: a?.left ?? null,
    };
  }, expectedTitle);

  expect(metrics.ok, JSON.stringify(metrics)).toBe(true);
  if (!metrics.ok) {
    return;
  }
  expect(metrics.headingVsParent, 'heading scrollWidth vs .hw-head__text clientWidth').toBeLessThanOrEqual(1);
  expect(metrics.headVsContent, '.hw-head scrollWidth vs .hw-page clientWidth').toBeLessThanOrEqual(1);
  expect(metrics.contentVsViewport, '.hw-page scrollWidth vs viewport').toBeLessThanOrEqual(1);
  expect(metrics.pageScrollOverflow, 'documentElement horizontal scroll').toBeLessThanOrEqual(1);
  expect(metrics.headingWidth).toBeLessThanOrEqual(metrics.contentWidth + 1);
  expect(metrics.headingLeft).toBeGreaterThanOrEqual(metrics.contentLeft - 1);
  expect(metrics.headingRight).toBeLessThanOrEqual(Math.min(metrics.contentRight, metrics.viewport) + 1);
  if (metrics.actionsTop !== null && metrics.actionsLeft !== null) {
    const sameRow = Math.abs(metrics.actionsTop - metrics.headingTop) < 48;
    if (sameRow) {
      expect(metrics.headingRight, 'title must not overlap actions on the same row').toBeLessThanOrEqual(metrics.actionsLeft + 1);
    } else {
      expect(metrics.actionsTop, 'actions wrap below the title').toBeGreaterThanOrEqual(metrics.headingBottom - 2);
    }
  }
}

test('teacher mixed homework list, draft detail and edit screens at four widths', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);

  const list = await page.goto(data.paths.homeworkList);
  expect(list?.status()).toBe(200);
  expect(list?.headers()['cache-control'] ?? '').toContain('no-store');
  await expect(page.getByRole('heading', { level: 1, name: 'Ödevlerim' })).toBeVisible();
  // Mixed list: Kesirler + uzun başlık + öğrencisiz sınıf taslağı, and one assigned homework.
  await expectCounts(page, '3', '5');
  await expect(page.getByText(data.paths.homeworkTitle, { exact: true })).toBeVisible();
  await expect(page.getByText(data.paths.homeworkAssignedTitle, { exact: true })).toBeVisible();
  await expect(page.locator('.hw-badge--draft').first()).toHaveText('Taslak');
  await expect(page.locator('.hw-badge--assigned')).toHaveCount(5);
  await expect(page.locator('.hw-badge--assigned').first()).toHaveText('Atandı');
  await expect(page.locator('tr', { hasText: data.paths.homeworkAssignedTitle })).toContainText(`${data.paths.homeworkAssignedRecipients} alıcı`);
  await expect(page.getByRole('link', { name: 'Ödevlerim', exact: true }).first()).toBeVisible();
  await expect(page.getByText(data.paths.foreignTitle)).toHaveCount(0);
  await noFakeControls(page);
  await expect(page.getByText('Yayında')).toHaveCount(0);
  await screens(page, info, 'odevler-karisik', data.paths.homeworkList);

  // Status filter splits assigned from draft rows while the counters stay real.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, `${data.paths.homeworkList}?durum=atandi`);
  await expect(page.locator('tbody tr')).toHaveCount(5);
  await expect(page.locator('tbody')).toContainText(data.paths.homeworkAssignedTitle);
  await expectCounts(page, '3', '5');
  await screens(page, info, 'odevler-atandi', `${data.paths.homeworkList}?durum=atandi`);
  await gotoOk(page, `${data.paths.homeworkList}?durum=taslak`);
  await expect(page.locator('tbody tr')).toHaveCount(3);
  await expect(page.locator('.hw-badge--assigned')).toHaveCount(0);

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
  await expect(page.getByRole('heading', { name: 'Aramana uygun ödev bulunamadı' })).toBeVisible();
  await expect(page.getByText('Henüz ödevin yok')).toHaveCount(0);
  await screens(page, info, 'odevler-arama-bos', `${data.paths.homeworkList}?q=bulunmayan-baslik`);

  // Search narrows the list; the counters keep describing the whole authorized set.
  await gotoOk(page, `${data.paths.homeworkList}?q=kesirler`);
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expectCounts(page, '3', '5');
  await expect(page.locator('[data-testid="hw-status-count"]')).toContainText('listelenen 1');

  await page.goto(data.paths.homeworkList);
  await page.getByRole('link', { name: `İncele: ${data.paths.homeworkTitle}` }).click();
  await expect(page).toHaveURL(/\/ogretmen\/odevler\/[0-9a-f-]{36}$/);
  await expect(page.getByRole('heading', { level: 1, name: data.paths.homeworkTitle })).toBeVisible();
  await expect(page.getByText('Bu taslak öğrencilere atanmadı; öğrenciler göremez.')).toBeVisible();
  await expect(page.getByText('15.06.2099 18:30')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Sınıfa ata' })).toBeVisible();
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
  await expectCounts(page, '0', '0');
  await expect(page.getByRole('heading', { name: 'Henüz ödevin yok' })).toBeVisible();
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
  await expectCounts(page, '3', '5');
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
  const longTitle = data.paths.homeworkLongTitle;
  expect(longTitle.length).toBeLessThanOrEqual(150);
  expect(longTitle.includes('ParcalanmayanKelime')).toBe(true);

  for (const viewport of HOMEWORK_VIEWPORTS) {
    await page.setViewportSize(viewport);
    await gotoOk(page, data.paths.homeworkLongDetail);
    await assertLayout(page);
    await assertHeadingFitsContent(page, longTitle);
    await shot(page, info, 'teacher-homework', 'odev-uzun-detay', viewport.name);
  }

  await screens(page, info, 'odev-uzun-duzenle', data.paths.homeworkLongEdit);

  await page.setViewportSize({ width: 390, height: 844 });
  await gotoOk(page, data.paths.homeworkList);
  await assertLayout(page);
  const listTitle = page.locator('.hw-title-text', { hasText: 'ParcalanmayanKelime' }).first();
  await expect(listTitle).toBeVisible();
  const listOverflow = await listTitle.evaluate((node) => {
    const el = node as HTMLElement;
    const cell = el.closest('td, .hw-table__title') as HTMLElement | null;
    return {
      title: el.scrollWidth - el.clientWidth,
      cell: cell ? cell.scrollWidth - cell.clientWidth : 0,
    };
  });
  expect(listOverflow.title).toBeLessThanOrEqual(1);
  expect(listOverflow.cell).toBeLessThanOrEqual(1);
  await shot(page, info, 'teacher-homework', 'odevler-uzun', '390');
  await assertClean(page);
});

test('teacher assign confirmation and assigned detail screens at four widths', async ({ page }, info) => {
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);

  // Confirm screen: reachable by GET only, shows the live eligible count, never submitted here.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkConfirm);
  const confirm = page.locator('[data-testid="hw-assign-confirm"]');
  await expect(confirm).toBeVisible();
  await expect(confirm.getByRole('heading', { name: 'Sınıfa ata' })).toBeVisible();
  await expect(page.locator('[data-testid="hw-eligible-count"]')).toHaveText('1');
  await expect(confirm.getByRole('button', { name: 'Atamayı onayla' })).toBeEnabled();
  await expect(confirm.getByRole('link', { name: 'Vazgeç' })).toBeVisible();
  await expect(confirm.getByLabel(/Bu ödevi sınıfa atamayı onaylıyorum/)).not.toBeChecked();
  await expect(confirm.getByText('Atama sırasında alıcılar sabitlenir.')).toBeVisible();
  await noFakeControls(page);
  await screens(page, info, 'odev-ata-onay', data.paths.homeworkConfirm);

  // Zero eligible recipients: explained, submit disabled.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkZeroConfirm);
  await expect(page.getByRole('heading', { level: 1, name: data.paths.homeworkZeroTitle })).toBeVisible();
  await expect(page.locator('[data-testid="hw-eligible-count"]')).toHaveText('0');
  await expect(page.getByText('Bu sınıfta atama için uygun aktif öğrenci yok.').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Atamayı onayla' })).toBeDisabled();
  await noFakeControls(page);
  await screens(page, info, 'odev-ata-bos', data.paths.homeworkZeroConfirm);

  // Assigned detail: frozen text, real recipient count, no edit / assign actions.
  await page.setViewportSize({ width: 1440, height: 900 });
  await gotoOk(page, data.paths.homeworkAssignedDetail);
  await expect(page.getByRole('heading', { level: 1, name: data.paths.homeworkAssignedTitle })).toBeVisible();
  await expect(page.locator('.hw-badge--assigned').first()).toHaveText('Atandı');
  await expect(page.getByText('Ödev metni donduruldu.')).toBeVisible();
  await expect(page.getByText('20.06.2099 10:00')).toBeVisible();
  await expect(page.locator('[data-testid="hw-recipient-count"]')).toHaveText(data.paths.homeworkAssignedRecipients);
  await expect(page.getByRole('link', { name: 'Düzenle' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Sınıfa ata' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Atamayı onayla' })).toHaveCount(0);
  await expect(page.locator('[data-testid="hw-assign-confirm"]')).toHaveCount(0);
  await expect(page.getByText('Bu taslak öğrencilere atanmadı')).toHaveCount(0);
  await noFakeControls(page);
  await screens(page, info, 'odev-atandi-detay', data.paths.homeworkAssignedDetail);

  // The assign link is gone and a stale confirm URL on an assigned homework shows the plain detail.
  await gotoOk(page, data.paths.homeworkAssignedConfirm);
  await expect(page.locator('[data-testid="hw-assign-confirm"]')).toHaveCount(0);
  // Edit is closed once assigned (request API keeps console noise out of the page watcher).
  const edit = await page.request.get(data.paths.homeworkAssignedEdit);
  expect(edit.status()).toBe(404);

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
    for (const path of [data.paths.homeworkConfirm, data.paths.homeworkAssignedDetail, data.paths.homeworkAssignedConfirm, data.paths.homeworkZeroConfirm]) {
      const denied = await page.goto(path);
      expect(denied?.status(), `${email} ${path}`).toBe(404);
    }
    await expect(page.getByText(data.paths.homeworkTitle)).toHaveCount(0);
    await expect(page.getByText(data.paths.homeworkAssignedTitle)).toHaveCount(0);
    await context.close();
  }

  const anonymous = await browser.newContext();
  const page = await anonymous.newPage();
  await page.goto(data.paths.homeworkList);
  await expect(page).toHaveURL(/\/giris$/);
  await anonymous.close();
});
