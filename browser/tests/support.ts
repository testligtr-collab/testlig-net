import { expect, type Page, type TestInfo } from '@playwright/test';
import { readFileSync } from 'node:fs';
import path from 'node:path';

export const VIDEO_HOSTS = ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'];
export const VIEWPORTS = [
  { name: '360', width: 360, height: 800 },
  { name: '768', width: 768, height: 1024 },
  { name: '1280', width: 1280, height: 900 },
] as const;

type Manifest = {
  password: string;
  users: Record<string, string>;
  paths: {
    topic: string;
    testCode: string;
    revision: string;
    reviewDetail: string;
    questionEdit: string;
    classroomName: string;
    contentTitle: string;
    testTitle: string;
  };
};

export function manifest(): Manifest {
  const file = process.env.BROWSER_MANIFEST
    ?? path.join(__dirname, '..', '..', 'var', 'browser-acceptance.json');
  return JSON.parse(readFileSync(file, 'utf8')) as Manifest;
}

export async function watch(page: Page): Promise<void> {
  const notes: string[] = [];
  page.on('pageerror', (error) => notes.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() !== 'error') {
      return;
    }
    const text = message.text();
    if (VIDEO_HOSTS.some((host) => text.includes(host.replace('https://', '')))) {
      return;
    }
    notes.push(`console: ${text}`);
  });
  page.on('requestfailed', (request) => {
    const url = request.url();
    const failure = request.failure()?.errorText ?? '';
    if (VIDEO_HOSTS.some((host) => url.startsWith(host)) || failure.includes('ERR_ABORTED')) {
      return;
    }
    notes.push(`failed: ${url} ${failure}`);
  });
  page.on('request', (request) => {
    const url = request.url();
    if (!url.startsWith('http')) {
      return;
    }
    const origin = new URL(url).origin;
    const allowed = new URL(process.env.BROWSER_BASE_URL ?? 'http://127.0.0.1:8080').origin;
    if (origin === allowed || VIDEO_HOSTS.some((host) => url.startsWith(host))) {
      return;
    }
    notes.push(`external: ${url}`);
  });
  await page.addInitScript(() => {
    const issues: string[] = [];
    document.addEventListener('securitypolicyviolation', (event) => {
      issues.push(`${event.violatedDirective} ${event.blockedURI}`);
    });
    Object.defineProperty(window, '__cspViolations', { value: issues });
  });
  (page as Page & { __notes?: string[] }).__notes = notes;
}

export function installVideoAbort(page: Page): void {
  for (const host of VIDEO_HOSTS) {
    page.route(`${host}/**`, (route) => route.abort());
  }
}

export async function assertClean(page: Page): Promise<void> {
  const violations = await page.evaluate(() => (window as unknown as { __cspViolations?: string[] }).__cspViolations ?? []);
  const notes = (page as Page & { __notes?: string[] }).__notes ?? [];
  expect(violations, 'CSP violations').toEqual([]);
  expect(notes, 'console, network, or mixed content').toEqual([]);
  const handlers = await page.locator('[onclick], [onerror], [onload], [onmouseover]').count();
  expect(handlers).toBe(0);
}

export async function assertLayout(page: Page): Promise<void> {
  const size = page.viewportSize();
  expect(size).not.toBeNull();
  const overflow = await page.evaluate(() => ({
    scroll: Math.max(document.documentElement.scrollWidth, document.body?.scrollWidth ?? 0),
    width: document.documentElement.clientWidth,
    outside: Array.from(document.querySelectorAll('main a, main button, header a, header button')).filter((node) => {
      const box = node.getBoundingClientRect();
      if (box.width === 0 || box.height === 0) {
        return false;
      }
      return box.right < 0 || box.left > window.innerWidth || box.bottom < 0;
    }).length,
  }));
  expect(overflow.scroll).toBeLessThanOrEqual(overflow.width + 1);
  expect(overflow.outside).toBe(0);
  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
}

export async function shot(page: Page, info: TestInfo, role: string, route: string, viewport: string): Promise<void> {
  const name = `${role}-${route}-${viewport}.png`;
  expect(name).not.toMatch(/@|[0-9a-f]{8}-[0-9a-f]{4}-/i);
  const file = path.join('artifacts', 'screenshots', name);
  await page.screenshot({ path: file, fullPage: false });
  await info.attach(name, { path: file, contentType: 'image/png' });
}

export async function login(page: Page, email: string): Promise<{ placeholder: string; postedToken: string }> {
  const data = manifest();
  await page.goto('/giris');
  const field = page.locator('input[name="_csrf_token"]');
  const placeholder = await field.inputValue();
  let postedToken = '';
  await page.route('**/giris', async (route) => {
    const request = route.request();
    if (request.method() === 'POST') {
      const body = request.postData() ?? '';
      const match = body.match(/_csrf_token=([^&]+)/);
      postedToken = match ? decodeURIComponent(match[1]) : '';
    }
    await route.continue();
  });
  await page.getByLabel('E-posta').fill(email);
  await page.getByLabel('Parola').fill(data.password);
  await page.getByRole('button', { name: 'Giriş yap' }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/giris'));
  return { placeholder, postedToken };
}

export async function openMobile(page: Page): Promise<void> {
  const toggle = page.getByRole('button', { name: 'Menüyü aç veya kapat' });
  if (await toggle.count() === 0 || !(await toggle.isVisible())) {
    return;
  }
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
}
