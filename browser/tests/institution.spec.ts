import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, openMobile, shot, VIEWPORTS, watch } from './support';

test('institution owner and assigned teacher stay inside their classroom', async ({ browser }, info) => {
  const data = manifest();
  const owner = await browser.newContext();
  const page = await owner.newPage();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.owner);
  await expect(page).toHaveURL(/\/kurum$/);
  for (const path of ['/kurum', '/kurum/siniflar', '/kurum/ogretmenler', '/kurum/ogrenciler', '/kurum/testler']) {
    const response = await page.goto(path);
    expect(response?.ok()).toBeTruthy();
    expect(await page.content()).not.toContain('@example.test');
    expect(await page.content()).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
  }
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/kurum');
    await assertLayout(page);
    if (viewport.width < 1024) {
      await openMobile(page);
    }
    await shot(page, info, 'owner', 'kurum', viewport.name);
  }
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/kurum/siniflar');
  await shot(page, info, 'owner', 'siniflar', '1280');
  await page.getByRole('link', { name: data.paths.classroomName }).first().click();
  await shot(page, info, 'owner', 'sinif', '1280');
  await page.goto('/kurum/ogretmenler');
  await shot(page, info, 'owner', 'ogretmenler', '1280');
  await page.goto('/kurum/ogretmenler/davet');
  await expect(page.getByLabel('Öğretmen e-postası')).toBeVisible();
  await expect(page.locator('#invite-email')).toHaveValue('');
  await shot(page, info, 'owner', 'davet', '1280');
  await page.goto('/kurum/ogrenciler');
  await shot(page, info, 'owner', 'ogrenciler', '1280');
  await page.goto('/kurum/testler');
  await shot(page, info, 'owner', 'testler', '1280');
  await assertClean(page);
  const missing = await page.goto('/kurum/siniflar/bbbbbbbbbbbbbbbbbbbb');
  expect(missing?.status()).toBe(404);
  await owner.close();

  const teacher = await browser.newContext();
  const teacherPage = await teacher.newPage();
  installVideoAbort(teacherPage);
  await watch(teacherPage);
  await login(teacherPage, data.users.teacher);
  await teacherPage.goto('/ogretmen/siniflarim');
  await expect(teacherPage.getByText(data.paths.classroomName)).toBeVisible();
  expect(await teacherPage.content()).not.toContain('@example.test');
  await assertClean(teacherPage);
  const admin = await teacherPage.goto('/kurum');
  expect(admin?.status()).toBe(403);
  await teacher.close();

  const platform = await browser.newContext();
  const platformPage = await platform.newPage();
  installVideoAbort(platformPage);
  await watch(platformPage);
  await login(platformPage, data.users.admin);
  const denied = await platformPage.goto('/kurum');
  expect(denied?.status()).toBe(403);
  await platform.close();
});
