import path from 'node:path';
import { expect, test, type Browser, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, watch } from './support';

const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1920', width: 1920, height: 1080 },
  { name: '768', width: 768, height: 1024 },
  { name: '390', width: 390, height: 844 },
] as const;

const PASSWORD_PATH = '/hesabim/sifre-degistir';

test('account password form layout, role shells, and failed-submit visuals', async ({ browser }, info) => {
  test.setTimeout(300_000);
  const data = manifest();
  const knownPassword = data.password;

  const sa = await openRole(browser, data.users.superadmin);
  for (const viewport of VIEWPORTS) {
    await sa.setViewportSize(viewport);
    await sa.goto(PASSWORD_PATH);
    await expect(sa.getByRole('heading', { level: 1, name: 'Parola değiştir' })).toBeVisible();
    await expect(sa.locator('.account-password-page')).toBeVisible();
    await expect(sa.locator('a.account-password__back[href="/hesabim"]')).toBeVisible();
    await expect(sa.locator('input[name="change_password_form[currentPassword]"]')).toHaveAttribute(
      'autocomplete',
      'current-password',
    );
    await expectEmptyPasswordInputs(sa);
    const submit = sa.locator('button.account-password__submit');
    const box = await submit.boundingBox();
    expect(box).toBeTruthy();
    expect(box!.height).toBeGreaterThanOrEqual(40);
    await submit.focus();
    const focusStyle = await submit.evaluate((node) => getComputedStyle(node).outlineStyle);
    expect(focusStyle).not.toBe('none');
    await assertLayout(sa);
    await capture(sa, info, `parola-bos-sa-${viewport.name}.png`);
  }
  await sa.context().close();

  const student = await openRole(browser, data.users.student);
  for (const viewport of VIEWPORTS) {
    await student.setViewportSize(viewport);
    await student.goto(PASSWORD_PATH);
    await expect(student.locator('.account-password-page.student-page, .account-password-page')).toBeVisible();
    await expect(student.getByRole('heading', { level: 1, name: 'Parola değiştir' })).toBeVisible();
    await expectEmptyPasswordInputs(student);
    await assertLayout(student);
    await capture(student, info, `parola-bos-ogrenci-${viewport.name}.png`);
  }
  await student.context().close();

  const teacher = await openRole(browser, data.users.teacher);
  const errorCases = [
    {
      name: 'yanlis',
      fill: {
        current: 'Yanlis-Parola-000!',
        first: 'Yeni-Guclu-Parola-999!',
        second: 'Yeni-Guclu-Parola-999!',
      },
      expectText: 'Parola değiştirilemedi',
    },
    {
      name: 'ayni',
      fill: {
        current: knownPassword,
        first: knownPassword,
        second: knownPassword,
      },
      expectText: 'farklı olmalıdır',
    },
    {
      name: 'eslesme',
      fill: {
        current: knownPassword,
        first: 'Yeni-Guclu-Parola-999!',
        second: 'Yeni-Guclu-Parola-111!',
      },
      expectText: 'eşleşmiyor',
    },
    {
      name: 'kisa',
      fill: {
        current: knownPassword,
        first: 'kisa12',
        second: 'kisa12',
      },
      expectText: 'Parolanız en az 8 karakter olmalıdır.',
    },
  ] as const;

  let expectedFormValidation422 = 0;
  for (const scenario of errorCases) {
    for (const viewport of VIEWPORTS) {
      await teacher.setViewportSize(viewport);
      await teacher.goto(PASSWORD_PATH);
      await teacher.locator('input[name="change_password_form[currentPassword]"]').fill(scenario.fill.current);
      await teacher.locator('input[name="change_password_form[newPassword][first]"]').fill(scenario.fill.first);
      await teacher.locator('input[name="change_password_form[newPassword][second]"]').fill(scenario.fill.second);
      const postResponse = teacher.waitForResponse((response) => {
        try {
          const url = new URL(response.url());
          return url.pathname === PASSWORD_PATH && response.request().method() === 'POST';
        } catch {
          return false;
        }
      });
      await teacher.locator('button.account-password__submit').click();
      const response = await postResponse;
      if (scenario.name === 'eslesme' || scenario.name === 'kisa') {
        expect(response.status(), `${scenario.name} @${viewport.name}`).toBe(422);
        expectedFormValidation422 += 1;
      } else {
        expect(response.ok(), `${scenario.name} @${viewport.name}`).toBeTruthy();
      }
      await expect(teacher.getByText(scenario.expectText)).toBeVisible();
      // Screenshot hygiene only — server value non-echo is asserted in ChangePasswordControllerTest.
      await clearPasswordInputs(teacher);
      await expectEmptyPasswordInputs(teacher);
      await assertLayout(teacher);
      await capture(teacher, info, `parola-${scenario.name}-${viewport.name}.png`);
    }
  }
  expect(expectedFormValidation422).toBe(8);

  await teacher.setViewportSize({ width: 1440, height: 900 });
  await teacher.goto(PASSWORD_PATH);
  // Drop only Chromium's console noise for the expected form-validation 422 POSTs above.
  const notes = (teacher as Page & { __notes?: string[] }).__notes ?? [];
  let ignoredValidationResponses = 0;
  (teacher as Page & { __notes?: string[] }).__notes = notes.filter((note) => {
    if (ignoredValidationResponses < expectedFormValidation422 && note.includes('status of 422')) {
      ignoredValidationResponses += 1;

      return false;
    }

    return true;
  });
  expect(ignoredValidationResponses).toBe(expectedFormValidation422);
  await assertClean(teacher);
  await teacher.context().close();
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

async function expectEmptyPasswordInputs(page: Page): Promise<void> {
  for (const name of [
    'change_password_form[currentPassword]',
    'change_password_form[newPassword][first]',
    'change_password_form[newPassword][second]',
  ]) {
    await expect(page.locator(`input[name="${name}"]`)).toHaveValue('');
  }
}

async function clearPasswordInputs(page: Page): Promise<void> {
  await page.locator('input[type="password"]').evaluateAll((nodes) => {
    for (const node of nodes) {
      if (node instanceof HTMLInputElement) {
        node.value = '';
      }
    }
  });
}

async function capture(page: Page, info: TestInfo, name: string): Promise<void> {
  await expectEmptyPasswordInputs(page);
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: true });
  await info.attach(name, { path: file, contentType: 'image/png' });
}
