import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('in-app confirm dialog gates publish without a native window.confirm', async ({ browser }, info) => {
  const data = manifest();
  const admin = await browser.newContext();
  const page = await admin.newPage();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.admin);
  const contentPosts: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().includes('/yayimla')) {
      contentPosts.push(request.url());
    }
  });

  await page.goto(data.paths.confirmDetail);
  await expect(page.getByRole('button', { name: 'Yayımla' })).toBeVisible();
  await expect(page.locator('#app-confirm-dialog')).toHaveAttribute('aria-modal', 'true');

  for (const viewport of VIEWPORTS) {
    contentPosts.length = 0;
    await page.setViewportSize(viewport);
    await page.goto(data.paths.confirmDetail);
    await assertLayout(page);
    await page.getByRole('button', { name: 'Yayımla' }).click();
    const dialog = page.getByRole('dialog', { name: 'İşlemi onayla' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText('İçeriği yayımlamak istediğinize emin misiniz?')).toBeVisible();
    const confirmBox = await dialog.getByRole('button', { name: 'Onayla' }).boundingBox();
    const cancelBox = await dialog.getByRole('button', { name: 'Vazgeç' }).boundingBox();
    expect(confirmBox).not.toBeNull();
    expect(cancelBox).not.toBeNull();
    expect(confirmBox!.height).toBeGreaterThanOrEqual(44);
    expect(cancelBox!.height).toBeGreaterThanOrEqual(44);
    expect(contentPosts).toHaveLength(0);
    await assertLayout(page);
    await shot(page, info, 'admin', 'onay-dialog', viewport.name);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page.getByText('İncelemede')).toBeVisible();
    expect(contentPosts).toHaveLength(0);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(data.paths.confirmDetail);
  await page.getByRole('button', { name: 'Yayımla' }).click();
  const confirmDialog = page.getByRole('dialog', { name: 'İşlemi onayla' });
  await expect(confirmDialog.getByRole('button', { name: 'Vazgeç' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(confirmDialog.getByRole('button', { name: 'Onayla' })).toBeFocused();
  await page.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Vazgeç' }).click();
  await expect(page.getByRole('dialog', { name: 'İşlemi onayla' })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Yayımla' })).toBeFocused();
  await expect(page.getByText('İncelemede')).toBeVisible();

  await page.getByRole('button', { name: 'Arşivle' }).click();
  await expect(page.getByRole('dialog', { name: 'İşlemi onayla' })).toBeVisible();
  await expect(page.getByText('Arşivleme geri alınamaz. Devam edilsin mi?')).toBeVisible();
  await page.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Vazgeç' }).click();
  await expect(page.getByText('İncelemede')).toBeVisible();

  contentPosts.length = 0;
  await page.getByRole('button', { name: 'Yayımla' }).click();
  await page.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Onayla' }).dblclick();
  await expect(page.getByText('Yayında')).toBeVisible();
  expect(contentPosts).toHaveLength(1);
  await assertClean(page);
  await admin.close();

  const teacher = await browser.newContext();
  const teacherPage = await teacher.newPage();
  installVideoAbort(teacherPage);
  await watch(teacherPage);
  await login(teacherPage, data.users.teacher);
  await teacherPage.goto(data.paths.reviewDetail);
  await expect(teacherPage.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await teacherPage.goto(data.paths.questionReview);
  await expect(teacherPage.getByRole('button', { name: 'Yayınla' })).toHaveCount(0);
  await assertClean(teacherPage);
  await teacher.close();

  const sa = await browser.newContext();
  const saPage = await sa.newPage();
  installVideoAbort(saPage);
  await watch(saPage);
  await login(saPage, data.users.superadmin);
  const questionPosts: string[] = [];
  saPage.on('request', (request) => {
    if (request.method() === 'POST' && request.url().includes('/yayinla')) {
      questionPosts.push(request.url());
    }
  });
  await saPage.goto(data.paths.questionReview);
  await expect(saPage.getByRole('button', { name: 'Yayınla' })).toBeVisible();
  await saPage.getByRole('button', { name: 'Yayınla' }).click();
  await expect(saPage.getByRole('dialog', { name: 'İşlemi onayla' })).toBeVisible();
  expect(questionPosts).toHaveLength(0);
  await saPage.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Vazgeç' }).click();
  await expect(saPage.getByText('İncelemede')).toBeVisible();

  await saPage.locator('form[action$="/yayinla"] input[name="_token"]').evaluate((node) => {
    if (node instanceof HTMLInputElement) {
      node.value = 'invalid-csrf';
    }
  });
  const csrfResponse = saPage.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/yayinla'));
  await saPage.getByRole('button', { name: 'Yayınla' }).click();
  await saPage.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Onayla' }).click();
  expect((await csrfResponse).status()).toBe(403);
  await saPage.goto(data.paths.questionReview);
  await expect(saPage.getByText('İncelemede')).toBeVisible();

  questionPosts.length = 0;
  await saPage.getByRole('button', { name: 'Yayınla' }).click();
  await saPage.getByRole('dialog', { name: 'İşlemi onayla' }).getByRole('button', { name: 'Onayla' }).click();
  await expect(saPage.getByText('Yayında')).toBeVisible();
  expect(questionPosts).toHaveLength(1);
  await assertClean(saPage);
  await sa.close();
});
