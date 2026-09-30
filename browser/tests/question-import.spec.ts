import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, VIEWPORTS, watch } from './support';

function code(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  return [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
}

function csv(data: ReturnType<typeof manifest>, questionCode: string, grade: string, stem: string): string {
  return [
    'code,grade_level,subject_code,learning_outcome_code,stem,option_a,option_b,option_c,option_d,correct_option,explanation',
    [questionCode, grade, data.paths.questionSubjectCode, data.paths.questionOutcomeCode, stem, 'Bir', 'Iki', '', '', 'A', ''].join(','),
  ].join('\n');
}

test('teacher previews and imports draft questions from csv', async ({ page }, info) => {
  installVideoAbort(page);
  await watch(page);
  const data = manifest();
  await login(page, data.users.teacher);
  const questionCode = code();

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await page.goto('/yonetim/sorular');
    await expect(page.getByRole('link', { name: 'CSV ile içe aktar' })).toBeVisible();
    await assertLayout(page);
  }

  await page.getByRole('link', { name: 'CSV ile içe aktar' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'CSV ile içe aktar' })).toBeVisible();
  const template = await page.request.get('/yonetim/sorular/ice-aktar/sablon');
  expect(template.headers()['cache-control'] ?? '').toContain('no-store');
  const body = await template.body();
  expect(body.subarray(0, 3).toString('utf8')).toBe('\uFEFF');

  await page.locator('#question-csv').setInputFiles({
    name: 'sorular.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv(data, questionCode, '1', 'Tarayici csv sorusu')),
  });
  await page.getByRole('button', { name: 'Önizle' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'İçe aktarma önizlemesi' })).toBeVisible();
  await expect(page.getByText('Oluşturulacak', { exact: true })).toBeVisible();
  const blocked = await page.locator('form.stack-sm').evaluate((form) => form instanceof HTMLFormElement && !form.checkValidity());
  expect(blocked).toBeTruthy();

  await page.getByRole('checkbox', { name: 'Önizlemeyi kontrol ettim; sorular taslak olarak oluşturulsun.' }).check();
  await page.getByRole('button', { name: 'Taslak olarak oluştur' }).click();
  await expect(page.getByText('1 soru taslak olarak oluşturuldu.')).toBeVisible();
  await expect(page.getByText('Taslak').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Yayınla' })).toHaveCount(0);

  await page.goto('/yonetim/sorular/ice-aktar');
  await page.locator('#question-csv').setInputFiles({
    name: 'sorular.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv(data, questionCode, '1', 'Baska metin')),
  });
  await page.getByRole('button', { name: 'Önizle' }).click();
  await expect(page.getByText('Atlanacak', { exact: true })).toBeVisible();
  await expect(page.getByText('Mevcut kayıt, güncellenmedi.')).toBeVisible();

  await page.goto('/yonetim/sorular/ice-aktar');
  await page.locator('#question-csv').setInputFiles({
    name: 'hatali.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(csv(data, code(), 'dokuz', 'Hatali satir')),
  });
  await page.getByRole('button', { name: 'Önizle' }).click();
  await expect(page.getByText('Sınıf 1 ile 12 arasında bir tam sayı olmalıdır.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Taslak olarak oluştur' })).toHaveCount(0);

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize(viewport);
    await assertLayout(page);
    await shot(page, info, 'teacher', 'soru-ice-aktar', viewport.name);
  }
  await assertClean(page);
});
