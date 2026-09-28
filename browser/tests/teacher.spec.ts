import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('teacher workspace editors run without a publish button', async ({ page }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);
  await page.goto('/hesabim');
  await expect(page).toHaveURL(/\/hesabim$/);
  const shell = await page.goto('/yonetim');
  expect(shell?.status()).toBe(403);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/icerikler');
    await assertLayout(page);
    await shot(page, info, 'teacher', 'calisma-alani', viewport.name);
  }

  await page.goto(data.paths.reviewDetail);
  await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await expect(page.getByText('Yayın bekleniyor')).toBeVisible();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.revision);
    await assertLayout(page);
    await shot(page, info, 'teacher', 'revision', viewport.name);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  const before = await page.getByText(/Blok \d+/).count();
  await page.locator('select[name="block_type"]').selectOption('paragraph');
  await page.getByRole('button', { name: 'Yeni blok ekle' }).click();
  await expect(page.getByText(/Blok \d+/)).toHaveCount(before + 1);
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Kaldır' }).first().click();
  await expect(page.getByText(/Blok \d+/)).toHaveCount(before);

  await page.locator('#revision-save-form textarea').first().fill('Kaydedilmeyen degisiklik');
  const blocked = await page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(blocked).toBeTruthy();

  await page.locator('#pdf-file').setInputFiles({
    name: 'ornek.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4\n'),
  });
  await expect(page.locator('#pdf-file-meta')).toContainText('ornek.pdf');
  await expect(page.getByLabel('Gerekçe kodu')).toHaveCount(0);

  await page.goto(data.paths.questionEdit);
  const options = page.locator('.option-row');
  const optionCount = await options.count();
  await page.locator('#add-option').click();
  await expect(options).toHaveCount(optionCount + 1);
  await page.locator('[data-remove-option]').last().click();
  await expect(options).toHaveCount(optionCount);
  await expect(page.getByText('correctStableKey')).toHaveCount(0);

  await page.goto('/yonetim/sorular');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await page.goto('/yonetim/testler');
  await expect(page.getByText(data.paths.testTitle)).toBeVisible();
  await assertClean(page);
});
