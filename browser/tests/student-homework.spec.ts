import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, login, manifest, shot } from './support';

const VIEWPORTS = [
  { name: '1920', width: 1920, height: 1080 },
  { name: '1440', width: 1440, height: 900 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

async function gotoOk(page: Page, path: string): Promise<void> {
  const response = await page.goto(path);
  expect(response?.status(), path).toBe(200);
}

async function noFake(page: Page): Promise<void> {
  await expect(page.locator('.shw-page').getByText(/Tasarım önerisi|Örnek veriler|puan|rubrik/i)).toHaveCount(0);
  await expect(page.locator('.shw-page').getByRole('link', { name: /Değerlendir|Dosya yükle|Yeniden teslim/i })).toHaveCount(0);
}

async function touchTargets(page: Page): Promise<void> {
  const small = await page.evaluate(() => Array.from(document.querySelectorAll('.shw-page a.btn, .shw-page button, .shw-page .shw-link, .shw-page textarea'))
    .filter((node) => {
      const box = node.getBoundingClientRect();
      return box.width > 0 && box.height > 0 && box.height < 44;
    })
    .map((node) => `${node.tagName.toLowerCase()} ${Math.round(node.getBoundingClientRect().height)}`));
  expect(small, 'interactive targets under 44px').toEqual([]);
}

async function assertTitleFits(page: Page): Promise<void> {
  const metrics = await page.evaluate(() => {
    const pageRoot = document.querySelector('.shw-page');
    const title = pageRoot?.querySelector('h1');
    if (!(pageRoot instanceof HTMLElement) || !(title instanceof HTMLElement)) {
      return { ok: false as const, reason: 'missing' };
    }
    const t = title.getBoundingClientRect();
    const p = pageRoot.getBoundingClientRect();
    return {
      ok: true as const,
      titleOverflow: title.scrollWidth - title.clientWidth,
      titlePastPage: t.right - p.right,
      pagePastViewport: pageRoot.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(metrics.ok, JSON.stringify(metrics)).toBe(true);
  if (metrics.ok) {
    expect(metrics.titleOverflow, 'title self-overflow').toBeLessThanOrEqual(1);
    expect(metrics.titlePastPage, 'title past page').toBeLessThanOrEqual(1);
    expect(metrics.pagePastViewport, 'page horizontal overflow').toBeLessThanOrEqual(1);
  }
}

async function screens(page: Page, info: TestInfo, route: string, path: string): Promise<void> {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await gotoOk(page, path);
    await assertLayout(page);
    await noFake(page);
    await assertTitleFits(page);
    if (viewport.width <= 768) {
      await touchTargets(page);
    }
    await shot(page, info, 'student-homework', route, viewport.name);
  }
}

test.describe('Student homework text submit', () => {
  test('mixed list and empty list', async ({ page }, info) => {
    const data = manifest();
    await login(page, data.users.student);
    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoOk(page, data.paths.studentHomeworkList);
    await expect(page.getByRole('heading', { name: 'Ödevlerim' })).toBeVisible();
    await expect(page.locator('.shw-pill--none').first()).toBeVisible();
    await expect(page.locator('.shw-pill--draft').first()).toBeVisible();
    await expect(page.locator('.shw-pill--submitted').first()).toBeVisible();
    await expect(page.locator('.shw-due-note').filter({ hasText: /Teslim s.*resi sona erdi/ }).first()).toBeVisible();
    await screens(page, info, 'list-mixed', data.paths.studentHomeworkList);

    // login() expects an anonymous /giris form; logout first (desktop + mobile both have CSRF).
    await page.getByRole('button', { name: 'Çıkış' }).first().click();
    await page.waitForURL('/');
    await login(page, data.users.otherStudent);
    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoOk(page, data.paths.studentHomeworkEmptyList);
    await expect(page.getByText('Şu an görüntülenecek ödevin yok.')).toBeVisible();
    await screens(page, info, 'list-empty', data.paths.studentHomeworkEmptyList);
    await assertClean(page);
  });

  test('empty response, draft, invalid submit confirm', async ({ page }, info) => {
    const data = manifest();
    await login(page, data.users.student);

    await screens(page, info, 'detail-none', data.paths.studentHomeworkNone);
    await expect(page.getByRole('heading', { name: data.paths.studentHomeworkNoneTitle })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ödevi gönder' })).toBeVisible();
    await expect(page.getByText('Taslak kaydetmek ödevini göndermez.')).toBeVisible();

    await screens(page, info, 'detail-draft', data.paths.studentHomeworkDraft);
    await expect(page.locator('#shw-body')).toHaveValue(/Tabak yuvarlak/);

    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoOk(page, data.paths.studentHomeworkNone);
    await page.getByRole('button', { name: 'Ödevi gönder' }).click();
    await expect(page.getByText(/onay|işaretle/i).first()).toBeVisible();
    await shot(page, info, 'student-homework', 'detail-confirm-error', '1440');
    await assertClean(page);
  });

  test('open confirm, submitted readonly, overdue draft, long title', async ({ page }, info) => {
    const data = manifest();
    await login(page, data.users.student);

    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoOk(page, data.paths.studentHomeworkDraft);
    await page.locator('input[name="confirm_submit"]').check();
    await expect(page.locator('input[name="confirm_submit"]')).toBeChecked();
    await shot(page, info, 'student-homework', 'detail-confirm-checked', '1440');

    await screens(page, info, 'detail-submitted', data.paths.studentHomeworkSubmitted);
    await expect(page.getByText('Gönderim:')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Ödevi gönder' })).toHaveCount(0);

    await screens(page, info, 'detail-overdue', data.paths.studentHomeworkOverdue);
    await expect(page.getByText(/Teslim süresi sona erdi/i).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Taslak kaydet' })).toHaveCount(0);

    await screens(page, info, 'detail-long', data.paths.studentHomeworkLong);
    await assertTitleFits(page);
    await assertClean(page);
  });
});
