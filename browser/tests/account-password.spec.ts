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
const UNRELATED_422_PATH = '/__x10_unrelated_422_probe';

type HttpFailure = {
  method: string;
  pathname: string;
  status: number;
};

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
  // HTTP is authoritative for 422/500. Playwright console location().url is a resource/source
  // location, not a request id — do not treat console text/time-window drops as route proof.
  const httpFailures = attachHttpFailureWatch(teacher);
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
  assertOnlyExpectedPasswordValidation422(httpFailures, expectedFormValidation422);

  const notes = pageNotes(teacher);
  await expect
    .poll(() => notes.filter((note) => note.startsWith('console:') && note.includes('status of 422')).length)
    .toBe(expectedFormValidation422);
  // Safe only after HTTP proved exactly those password POST 422s and no other 422/500.
  dropPasswordFormConsole422Noise(notes, expectedFormValidation422);

  await teacher.setViewportSize({ width: 1440, height: 900 });
  await teacher.goto(PASSWORD_PATH);
  await assertClean(teacher);
  await teacher.context().close();
});

test('password validation window fails closed on unrelated route 422', async ({ browser }) => {
  const data = manifest();
  const page = await openRole(browser, data.users.teacher);
  const httpFailures = attachHttpFailureWatch(page);

  await page.route(`**${UNRELATED_422_PATH}`, async (route) => {
    await route.fulfill({
      status: 422,
      contentType: 'text/plain',
      body: 'unrelated-route-422-probe',
    });
  });

  await page.goto(PASSWORD_PATH);
  await page.locator('input[name="change_password_form[currentPassword]"]').fill(data.password);
  await page.locator('input[name="change_password_form[newPassword][first]"]').fill('Yeni-Guclu-Parola-999!');
  await page.locator('input[name="change_password_form[newPassword][second]"]').fill('Yeni-Guclu-Parola-111!');

  const postResponse = page.waitForResponse((response) => {
    try {
      const url = new URL(response.url());
      return url.pathname === PASSWORD_PATH && response.request().method() === 'POST';
    } catch {
      return false;
    }
  });
  await Promise.all([
    page.locator('button.account-password__submit').click(),
    page.evaluate((probePath) => fetch(probePath), UNRELATED_422_PATH),
  ]);
  const response = await postResponse;
  expect(response.status()).toBe(422);
  await expect(page.getByText('eşleşmiyor')).toBeVisible();

  expect(
    httpFailures.some(
      (failure) => failure.pathname === UNRELATED_422_PATH && failure.status === 422,
    ),
    'unrelated route 422 must be recorded in the validation window',
  ).toBe(true);
  expect(
    httpFailures.some(
      (failure) =>
        failure.pathname === PASSWORD_PATH && failure.method === 'POST' && failure.status === 422,
    ),
  ).toBe(true);
  // Same gate the visual suite uses — must not treat the window as clean.
  let gateAcceptedUnrelated422 = false;
  try {
    assertOnlyExpectedPasswordValidation422(httpFailures, 1);
    gateAcceptedUnrelated422 = true;
  } catch {
    // expected: unrelated route 422 must keep the window unclean
  }
  expect(gateAcceptedUnrelated422, 'HTTP gate must fail closed on same-window unrelated 422').toBe(
    false,
  );

  await page.context().close();
});

/**
 * Record 422 and 5xx responses with route/method/status.
 * Playwright ConsoleMessage.location().url is resource/source metadata, not request identity.
 */
function attachHttpFailureWatch(page: Page): HttpFailure[] {
  const failures: HttpFailure[] = [];
  page.on('response', (response) => {
    const status = response.status();
    if (status !== 422 && status < 500) {
      return;
    }
    try {
      const url = new URL(response.url());
      failures.push({
        method: response.request().method(),
        pathname: url.pathname,
        status,
      });
    } catch {
      failures.push({
        method: response.request().method(),
        pathname: response.url(),
        status,
      });
    }
  });

  return failures;
}

function assertOnlyExpectedPasswordValidation422(failures: HttpFailure[], expectedCount: number): void {
  const expected = failures.filter(
    (failure) =>
      failure.pathname === PASSWORD_PATH && failure.method === 'POST' && failure.status === 422,
  );
  const unexpected = failures.filter(
    (failure) =>
      !(failure.pathname === PASSWORD_PATH && failure.method === 'POST' && failure.status === 422),
  );
  expect(unexpected, 'unexpected HTTP 422/500 outside password validation POST').toEqual([]);
  expect(expected, 'expected password validation POST 422 count').toHaveLength(expectedCount);
}

function dropPasswordFormConsole422Noise(notes: string[], expectedCount: number): void {
  let removed = 0;
  const kept: string[] = [];
  for (const note of notes) {
    if (removed < expectedCount && note.startsWith('console:') && note.includes('status of 422')) {
      removed += 1;
      continue;
    }
    kept.push(note);
  }
  notes.length = 0;
  notes.push(...kept);
  expect(removed).toBe(expectedCount);
}

function pageNotes(page: Page): string[] {
  return (page as Page & { __notes?: string[] }).__notes ?? [];
}

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
