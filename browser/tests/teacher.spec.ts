import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

test('teacher workspace editors run without a publish button', async ({ page }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);
  await expect(page).toHaveURL(/\/calisma-alani$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Çalışma alanı' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ödemeler' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Özet', exact: true })).toHaveCount(0);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/hesabim');
  await expect(page).toHaveURL(/\/hesabim$/);
  const shell = await page.request.get('/yonetim');
  expect(shell.status()).toBe(403);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    const workspace = await page.goto('/calisma-alani');
    expect(workspace?.headers()['cache-control'] ?? '').toContain('no-store');
    expect(workspace?.headers()['x-robots-tag'] ?? '').toContain('noindex');
    expect(workspace?.headers()['x-frame-options'] ?? '').toBe('DENY');
    expect(workspace?.headers()['x-content-type-options'] ?? '').toBe('nosniff');
    expect(workspace?.headers()['content-security-policy'] ?? '').toContain("default-src 'self'");
    expect(workspace?.headers()['referrer-policy'] ?? '').toBe('no-referrer');
    await expect(page.locator('.panel-role')).toHaveText('Öğretmen');
    await expect(page.getByRole('heading', { level: 1, name: 'Çalışma alanı' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ödemeler' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
    await expect(page.locator('#review-queue')).toHaveCount(0);
    await expect(page.locator('[data-metric="drafts"] .workspace-metric__value')).toHaveText('1');
    await expect(page.locator('[data-metric="classrooms"] .workspace-metric__value')).toHaveText('1');
    await expect(page.getByText(data.paths.foreignTitle)).toHaveCount(0);
    await expect(page.getByText(data.paths.endedClassroom)).toHaveCount(0);
    await assertLayout(page);
    await shot(page, info, 'teacher', 'calisma-alani', viewport.name);
    if (viewport.width === 360) {
      const toggle = page.getByRole('button', { name: 'Menü', exact: true });
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await page.keyboard.press('Escape');
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    }
  }

  await page.goto(data.paths.reviewDetail);
  await expect(page.getByRole('button', { name: 'Yayımla' })).toHaveCount(0);
  await expect(page.getByText('Yayın bekleniyor')).toBeVisible();
  await shot(page, info, 'teacher', 'icerik', '1280');

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto(data.paths.revision);
    await assertLayout(page);
    await shot(page, info, 'teacher', 'revision', viewport.name);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  const blocks = page.locator('span.muted', { hasText: /^Blok \d+:$/ });
  const before = await blocks.count();
  await page.locator('select[name="block_type"]').selectOption('paragraph');
  await page.getByRole('button', { name: 'Yeni blok ekle' }).click();
  await expect(blocks).toHaveCount(before + 1);
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Kaldır' }).first().click();
  await expect(blocks).toHaveCount(before);

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
  await shot(page, info, 'teacher', 'soru', '1280');
  const options = page.locator('.option-row');
  const optionCount = await options.count();
  await page.locator('#add-option').click();
  await expect(options).toHaveCount(optionCount + 1);
  await page.locator('[data-remove-option]').last().click();
  await expect(options).toHaveCount(optionCount);
  await expect(page.getByText('correctStableKey')).toHaveCount(0);

  await page.goto('/yonetim/icerikler');
  await expect(page.getByRole('link', { name: 'Çalışma alanı' }).first()).toBeVisible();
  await shot(page, info, 'teacher', 'icerikler', '1280');
  await page.goto('/yonetim/sorular');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await shot(page, info, 'teacher', 'sorular', '1280');
  await page.goto('/yonetim/testler');
  await expect(page.getByText(data.paths.testTitle)).toBeVisible();
  await shot(page, info, 'teacher', 'testler', '1280');
  await page.goto('/ogretmen/siniflarim');
  await expect(page.getByRole('heading', { level: 1, name: 'Sınıflarım' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ödemeler' })).toHaveCount(0);
  await shot(page, info, 'teacher', 'siniflar', '1280');
  await assertClean(page);
});
