import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, login, manifest, shot, watch } from './support';

async function openAs(browser: Browser, email: string): Promise<{ page: Page; close: () => Promise<void> }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await watch(page);
  await login(page, email);
  return { page, close: () => context.close() };
}

test('anonymous workspace visit opens the login page', async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto('/calisma-alani');
  await expect(page).toHaveURL(/\/giris$/);
  await context.close();
});

test('moderator workspace shows the review queue without create actions', async ({ browser }, info: TestInfo) => {
  const data = manifest();
  const session = await openAs(browser, data.users.moderator);
  await session.page.setViewportSize({ width: 1280, height: 900 });
  await session.page.goto('/calisma-alani');
  await expect(session.page.locator('#review-queue')).toBeVisible();
  await expect(session.page.getByRole('link', { name: 'İnceleme kuyruğu' })).toBeVisible();
  await expect(session.page.getByRole('link', { name: 'Yeni içerik' })).toHaveCount(0);
  await expect(session.page.getByRole('link', { name: 'Yeni soru' })).toHaveCount(0);
  await expect(session.page.getByRole('link', { name: 'Yeni test' })).toHaveCount(0);
  await expect(session.page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await expect(session.page.getByText(data.paths.foreignTitle)).toHaveCount(0);
  await assertLayout(session.page);
  await shot(session.page, info, 'moderator', 'calisma-alani', '1280');
  await assertClean(session.page);
  await session.close();
});

test('expert workspace can start content but not publish', async ({ browser }, info: TestInfo) => {
  const session = await openAs(browser, manifest().users.expert);
  await session.page.setViewportSize({ width: 1280, height: 900 });
  await session.page.goto('/calisma-alani');
  await expect(session.page.getByRole('link', { name: 'Yeni içerik' })).toBeVisible();
  await expect(session.page.locator('.panel-role')).toHaveText('Uzman Öğretmen');
  await expect(session.page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await assertLayout(session.page);
  await shot(session.page, info, 'expert', 'calisma-alani', '1280');
  await assertClean(session.page);
  await session.close();
});

test('empty teacher workspace shows zeros and no other draft', async ({ browser }, info: TestInfo) => {
  const data = manifest();
  const session = await openAs(browser, data.users.emptyTeacher);
  await session.page.setViewportSize({ width: 1280, height: 900 });
  await session.page.emulateMedia({ reducedMotion: 'reduce' });
  await session.page.goto('/calisma-alani');
  await expect(session.page.locator('[data-metric="drafts"] .workspace-metric__value')).toHaveText('0');
  await expect(session.page.locator('[data-metric="classrooms"] .workspace-metric__value')).toHaveText('0');
  await expect(session.page.locator('#review-queue')).toHaveCount(0);
  await expect(session.page.getByText(data.paths.foreignTitle)).toHaveCount(0);
  await expect(session.page.getByText('Güncel içerik yok')).toBeVisible();
  await assertLayout(session.page);
  await shot(session.page, info, 'teacher-empty', 'calisma-alani', '1280');
  await assertClean(session.page);
  await session.close();
});

test('admin and superadmin overview stays on yonetim', async ({ browser }) => {
  const data = manifest();
  const admin = await openAs(browser, data.users.admin);
  await admin.page.goto('/yonetim');
  await expect(admin.page.getByRole('link', { name: 'Genel Bakış', exact: true })).toHaveAttribute('href', '/yonetim');
  expect((await admin.page.request.get('/calisma-alani')).status()).toBe(200);
  await assertClean(admin.page);
  await admin.close();

  const superadmin = await openAs(browser, data.users.superadmin);
  await superadmin.page.goto('/yonetim');
  await expect(superadmin.page.getByRole('link', { name: 'Genel Bakış', exact: true })).toHaveAttribute('href', '/yonetim');
  await assertClean(superadmin.page);
  await superadmin.close();
});

test('student parent and institution owner cannot open the workspace', async ({ browser }) => {
  const data = manifest();
  for (const email of [data.users.student, data.users.parent, data.users.owner]) {
    const session = await openAs(browser, email);
    await assertClean(session.page);
    expect((await session.page.request.get('/calisma-alani')).status()).toBe(403);
    await session.close();
  }
});
