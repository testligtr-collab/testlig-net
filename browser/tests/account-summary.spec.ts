import path from 'node:path';
import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

test('account summary layout for roles, membership, workspace, and long wrap', async ({ browser }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  const accountPath = data.paths.accountSummary as string;
  const longEmail = data.paths.accountLongEmail as string;
  const longInstitution = data.paths.accountLongInstitution as string;
  const longClassroom = data.paths.accountLongClassroom as string;
  const longUser = data.paths.accountLongUser as string;
  expect(accountPath).toBe('/hesabim');
  expect(longEmail.length).toBeGreaterThan(40);
  expect(longInstitution.length).toBeGreaterThan(30);
  expect(longClassroom.length).toBeGreaterThan(30);

  const sa = await openRole(browser, data.users.superadmin);
  for (const viewport of VIEWPORTS) {
    await sa.setViewportSize(viewport);
    await sa.goto(accountPath);
    await expect(sa.getByRole('heading', { level: 1, name: 'Hesabım' })).toBeVisible();
    await expect(sa.getByText('salt okunur özeti')).toBeVisible();
    await expect(sa.locator('.account-summary-page')).toBeVisible();
    await expect(sa.getByRole('heading', { level: 2, name: 'Kimlik' })).toBeVisible();
    await expect(sa.getByRole('heading', { level: 2, name: 'Çalışma alanı' })).toBeVisible();
    await expect(sa.locator('a.account-summary__action[href="/hesabim/sifre-degistir"]')).toBeVisible();
    await expect(sa.locator('a.account-summary__action[href="/yonetim/icerikler"]')).toBeVisible();
    const password = sa.locator('a.account-summary__action[href="/hesabim/sifre-degistir"]');
    const box = await password.boundingBox();
    expect(box).toBeTruthy();
    expect(box!.height).toBeGreaterThanOrEqual(40);
    await password.focus();
    const focusStyle = await password.evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(focusStyle).not.toBe('none');
    await assertLayout(sa);
    await capture(sa, info, `hesabim-sa-${viewport.name}.png`);
  }
  await sa.context().close();

  const teacher = await openRole(browser, data.users.teacher);
  for (const viewport of VIEWPORTS) {
    await teacher.setViewportSize(viewport);
    await teacher.goto(accountPath);
    await expect(teacher.getByRole('heading', { level: 1, name: 'Hesabım' })).toBeVisible();
    await expect(teacher.getByRole('heading', { level: 2, name: 'Kurum üyeliklerim' })).toBeVisible();
    await expect(teacher.getByText('Sinif A')).toBeVisible();
    await expect(teacher.locator('a.account-summary__action[href="/ogretmen/siniflarim"]')).toBeVisible();
    await expect(teacher.getByRole('heading', { level: 2, name: 'Çalışma alanı' })).toBeVisible();
    await assertLayout(teacher);
    await capture(teacher, info, `hesabim-ogretmen-${viewport.name}.png`);
  }
  await teacher.context().close();

  const student = await openRole(browser, data.users.student);
  for (const viewport of VIEWPORTS) {
    await student.setViewportSize(viewport);
    await student.goto(accountPath);
    await expect(student.getByRole('heading', { level: 1, name: 'Hesabım' })).toBeVisible();
    await expect(student.getByRole('heading', { level: 2, name: 'Kimlik' })).toBeVisible();
    await expect(student.getByRole('heading', { level: 2, name: 'Çalışma alanı' })).toHaveCount(0);
    await expect(student.locator('a[href="/yonetim/icerikler"]')).toHaveCount(0);
    await expect(student.locator('a[href="/yonetim/sorular"]')).toHaveCount(0);
    await expect(student.locator('a.account-summary__action[href="/hesabim/sifre-degistir"]')).toBeVisible();
    // Shared browser seed may still attach a student membership; workspace links must stay absent.
    await assertLayout(student);
    await capture(student, info, `hesabim-ogrenci-${viewport.name}.png`);
  }
  await student.context().close();

  const long = await openRole(browser, longUser);
  for (const viewport of VIEWPORTS) {
    await long.setViewportSize(viewport);
    await long.goto(accountPath);
    await expect(long.locator('.account-summary__value--wrap')).toContainText(longEmail);
    await expect(long.locator('.account-summary__membership-name')).toContainText(longInstitution);
    await expect(long.locator('.account-summary__membership-classrooms')).toContainText(longClassroom);
    const metrics = await long.locator('.account-summary__membership').first().evaluate((el) => {
      const box = el.getBoundingClientRect();
      return {
        overflow: el.scrollWidth - el.clientWidth,
        right: box.right - document.documentElement.clientWidth,
        pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    expect(metrics.overflow).toBeLessThanOrEqual(1);
    expect(metrics.right).toBeLessThanOrEqual(1);
    expect(metrics.pageOverflow).toBeLessThanOrEqual(1);
    await assertLayout(long);
    await capture(long, info, `hesabim-uzun-${viewport.name}.png`);
  }

  await long.setViewportSize({ width: 1440, height: 900 });
  await long.goto(accountPath);
  await assertClean(long);
  await long.context().close();
  // Do not open or submit the password form in this slice.
});

async function openRole(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  installVideoAbort(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await watch(page);
  await login(page, email);

  return page;
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: true });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
