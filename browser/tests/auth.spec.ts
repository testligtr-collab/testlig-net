import { expect, test } from '@playwright/test';
import { assertClean, installVideoAbort, login, manifest, watch } from './support';

test('separate contexts log in, reject a token without its cookie, and log out with POST', async ({ browser, request }) => {
  const data = manifest();
  const cases = [
    { email: data.users.teacher, path: /\/hesabim$/ },
    { email: data.users.student, path: /\/ogrenci$/ },
    { email: data.users.superadmin, path: /\/hesabim$/ },
  ];
  let captured = '';
  for (const entry of cases) {
    const context = await browser.newContext();
    const page = await context.newPage();
    installVideoAbort(page);
    await watch(page);
    const result = await login(page, entry.email);
    expect(result.placeholder).toMatch(/^[-_a-zA-Z0-9]{4,22}$/);
    expect(result.postedToken.length).toBeGreaterThanOrEqual(24);
    expect(result.postedToken).not.toBe(result.placeholder);
    await expect(page).toHaveURL(entry.path);
    await assertClean(page);
    captured = result.postedToken;
    const denied = await page.request.get('/cikis');
    expect(denied.status()).toBe(405);
    await expect(page).toHaveURL(entry.path);
    await page.getByRole('button', { name: 'Çıkış' }).first().click();
    await page.waitForURL('/');
    const privatePage = await page.goto('/hesabim');
    expect(privatePage?.url() ?? page.url()).toContain('/giris');
    await context.close();
  }

  const fresh = await browser.newContext();
  const isolated = await fresh.newPage();
  await isolated.goto('/ogrenci');
  await expect(isolated).toHaveURL(/\/giris/);
  await fresh.close();

  const replay = await request.post('/giris', {
    form: {
      _username: data.users.teacher,
      _password: data.password,
      _csrf_token: captured,
    },
    maxRedirects: 0,
  });
  expect(replay.headers()['location'] ?? '').not.toMatch(/\/(hesabim|ogrenci|kurum|yonetim|veli)/);
});
