import { expect, test, type Page, type TestInfo } from '@playwright/test';
import { assertClean, assertLayout, installVideoAbort, login, manifest, shot, watch } from './support';

// M29-cms: one working visitor navigation across the homepage, public pages,
// catalog and legal shells, measured at 390 px and 1280 px.

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 900 },
] as const;

const NAV = [
  { label: 'Ana Sayfa', href: '/', heading: null },
  { label: 'Sınıflar', href: '/dersler', heading: 'Dersleri keşfet' },
  { label: 'Haberler', href: '/haberler', heading: 'Haberler' },
] as const;

const SHELLS = [
  { path: '/', name: 'home', current: '/' },
  { path: '/dersler', name: 'dersler', current: '/dersler' },
  { path: '/haberler', name: 'haberler', current: '/haberler' },
  { path: '/giris', name: 'giris', current: null },
  { path: '/kayit', name: 'kayit', current: null },
  { path: '/gizlilik', name: 'gizlilik', current: null },
] as const;

const DEAD_FRAGMENTS = ['sinavlar', 'ogrenme', 'guven', 'akis', 'testler', 'dokumanlar', 'simulasyonlar', 'videolar', 'soru-cevap', 'oyunlar'];

test.beforeEach(async ({ page }) => {
  installVideoAbort(page);
  await watch(page);
});

async function overflow(page: Page): Promise<{ scrollWidth: number; clientWidth: number }> {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
}

async function recordOverflow(page: Page, record: string[], label: string): Promise<void> {
  const metrics = await overflow(page);
  record.push(`${label}: scrollWidth=${metrics.scrollWidth} clientWidth=${metrics.clientWidth} diff=${metrics.scrollWidth - metrics.clientWidth}`);
  expect(metrics.scrollWidth - metrics.clientWidth, `${label} horizontal overflow`).toBeLessThanOrEqual(1);
}

async function openDrawer(page: Page) {
  const toggle = page.getByRole('button', { name: 'Menüyü aç veya kapat' });
  await expect(toggle).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const drawer = page.locator('.mobile-drawer');
  await expect(drawer).toBeVisible();

  return { toggle, drawer };
}

test('anonymous visitor gets the same working menu in every public shell at 390 and 1280', async ({ page }, info) => {
  const record: string[] = [];

  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    for (const shell of SHELLS) {
      const response = await page.goto(shell.path);
      expect(response?.status(), shell.path).toBe(200);
      await assertLayout(page);
      await assertClean(page);
      await recordOverflow(page, record, `${viewport.name} ${shell.path}`);

      const desktopNav = page.locator('nav[aria-label="Ana menü"]');
      const mobileNav = page.locator('nav[aria-label="Mobil ana menü"]');

      if (viewport.width >= 1280) {
        await expect(desktopNav).toBeVisible();
        await expect(page.getByRole('button', { name: 'Menüyü aç veya kapat' })).toBeHidden();
        const links = desktopNav.getByRole('link');
        await expect(links).toHaveCount(NAV.length);
        for (const [index, item] of NAV.entries()) {
          await expect(links.nth(index)).toHaveText(item.label);
          await expect(links.nth(index)).toHaveAttribute('href', item.href);
          await expect(links.nth(index)).toBeVisible();
        }
        if (shell.current) {
          await expect(desktopNav.locator('a[aria-current="page"]')).toHaveAttribute('href', shell.current);
        } else {
          await expect(desktopNav.locator('a[aria-current="page"]')).toHaveCount(0);
        }
        const actions = page.locator('.site-header__actions');
        await expect(actions.getByRole('link', { name: 'Giriş Yap' })).toHaveAttribute('href', '/giris');
        await expect(actions.getByRole('link', { name: 'Kayıt Ol' })).toHaveAttribute('href', '/kayit');
        await expect(actions.getByRole('link', { name: 'Giriş Yap' })).toBeVisible();
        await expect(actions.getByRole('link', { name: 'Kayıt Ol' })).toBeVisible();
        await expect(actions.locator('form')).toHaveCount(0);
      } else {
        await expect(desktopNav).toBeHidden();
        await expect(mobileNav).toBeHidden();
        const { toggle, drawer } = await openDrawer(page);
        const box = await toggle.boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        expect(box?.width ?? 0).toBeGreaterThanOrEqual(44);

        const links = mobileNav.getByRole('link');
        await expect(links).toHaveCount(NAV.length);
        for (const [index, item] of NAV.entries()) {
          await expect(links.nth(index)).toHaveText(item.label);
          await expect(links.nth(index)).toHaveAttribute('href', item.href);
          await expect(links.nth(index)).toBeVisible();
        }
        if (shell.current) {
          await expect(mobileNav.locator('a[aria-current="page"]')).toHaveAttribute('href', shell.current);
        }
        const actions = drawer.locator('.mobile-drawer__actions');
        await expect(actions.getByRole('link', { name: 'Giriş Yap' })).toBeVisible();
        await expect(actions.getByRole('link', { name: 'Kayıt Ol' })).toBeVisible();
        await recordOverflow(page, record, `${viewport.name} ${shell.path} (menu open)`);

        // Keyboard: items are reachable and Escape closes with focus restored.
        await links.nth(0).focus();
        await expect(links.nth(0)).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(links.nth(1)).toBeFocused();

        if (['home', 'dersler', 'giris'].includes(shell.name)) {
          await shot(page, info, 'public-nav', `${shell.name}-menu-open`, viewport.name);
        }

        await page.keyboard.press('Escape');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(drawer).toBeHidden();
        await expect(toggle).toBeFocused();
      }

      if (viewport.width >= 1280 && ['home', 'dersler', 'haberler', 'giris', 'gizlilik'].includes(shell.name)) {
        await shot(page, info, 'public-nav', `${shell.name}-menu`, viewport.name);
      }
    }
  }

  await info.attach('public-nav-overflow.txt', { body: record.join('\n'), contentType: 'text/plain' });
});

test('every visible main menu link reaches its real page and the drawer never blocks navigation', async ({ page }) => {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });

    for (const item of NAV) {
      await page.goto('/gizlilik');
      if (viewport.width < 1024) {
        await openDrawer(page);
        await page.locator('nav[aria-label="Mobil ana menü"]').getByRole('link', { name: item.label }).click();
      } else {
        await page.locator('nav[aria-label="Ana menü"]').getByRole('link', { name: item.label }).click();
      }
      await page.waitForURL((url) => url.pathname === item.href);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
      if (item.heading) {
        await expect(page.getByRole('heading', { level: 1, name: item.heading })).toBeVisible();
      }
      if (viewport.width < 1024) {
        await expect(page.getByRole('button', { name: 'Menüyü aç veya kapat' })).toHaveAttribute('aria-expanded', 'false');
        await expect(page.locator('.mobile-drawer')).toBeHidden();
      }
    }
  }
});

test('no public shell links to a missing section and footers keep working legal links', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/');
  const homeIds = await page.evaluate(() => Array.from(document.querySelectorAll('[id]')).map((node) => node.id));
  const homeFragments = await page.evaluate(() => Array.from(document.querySelectorAll('a[href^="#"]')).map((node) => node.getAttribute('href') ?? ''));
  for (const fragment of homeFragments) {
    expect(fragment, 'empty fragment link').not.toBe('#');
    expect(homeIds, `homepage anchor ${fragment}`).toContain(fragment.slice(1));
  }

  for (const shell of SHELLS) {
    await page.goto(shell.path);
    const hrefs = await page.evaluate(() => Array.from(document.querySelectorAll('header a, footer a, main a')).map((node) => node.getAttribute('href') ?? ''));
    for (const href of hrefs) {
      for (const dead of DEAD_FRAGMENTS) {
        expect(href, `${shell.path} links to #${dead}`).not.toBe(`/#${dead}`);
        expect(href, `${shell.path} links to #${dead}`).not.toBe(`#${dead}`);
      }
      if (href.startsWith('/#')) {
        expect(homeIds, `${shell.path} link ${href}`).toContain(href.slice(2));
      }
      if (shell.path !== '/') {
        expect(href.startsWith('#') && href.length > 1 && href !== '#main-content', `${shell.path} stray in-page anchor ${href}`).toBe(false);
      }
    }
    const footer = page.locator('footer');
    await expect(footer.getByRole('link', { name: 'Gizlilik' }).first()).toHaveAttribute('href', '/gizlilik');
    await expect(footer.getByRole('link', { name: 'Kullanım Koşulları' }).first()).toHaveAttribute('href', '/kullanim-kosullari');
    await expect(footer.getByRole('link', { name: 'Çerez Politikası' }).first()).toHaveAttribute('href', '/cerez-politikasi');
    await expect(footer.getByRole('link', { name: 'Çocuk ve Veli Bilgilendirmesi' }).first()).toHaveAttribute('href', '/cocuk-ve-veli-bilgilendirmesi');
  }

  for (const legal of ['/gizlilik', '/kullanim-kosullari', '/cerez-politikasi', '/cocuk-ve-veli-bilgilendirmesi']) {
    const response = await page.goto(legal);
    expect(response?.status(), legal).toBe(200);
  }
});

test('homepage news preview links to /haberler and showcase cards are marked as preparing without fake controls', async ({ page }, info) => {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto('/');

    const news = page.locator('#haberler');
    await expect(news).toContainText('Haberler ve öğrenme rehberleri');
    await expect(news.getByRole('link', { name: /Tüm haberler/ })).toHaveAttribute('href', '/haberler');
    await expect(page.locator('footer').getByRole('link', { name: 'Haberler', exact: true })).toHaveAttribute('href', '/haberler');

    const cards = page.locator('#vitrin article.hp-vcard');
    await expect(cards).toHaveCount(6);
    for (let index = 0; index < 6; index += 1) {
      const card = cards.nth(index);
      await expect(card).toContainText('İçerikler hazırlanıyor');
      await expect(card.locator('a, button, [role="button"]')).toHaveCount(0);
    }

    await cards.first().scrollIntoViewIfNeeded();
    await shot(page, info, 'public-nav', 'home-showcase', viewport.name);
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/haberler');
  await expect(page.getByRole('heading', { level: 1, name: 'Haberler' })).toBeVisible();
});

test('signed-in visitor keeps Hesabım and CSRF logout in the shared header at 1280 and in the 390 drawer', async ({ browser }, info) => {
  const data = manifest();

  const desktop = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await desktop.newPage();
  installVideoAbort(page);
  await watch(page);
  await login(page, data.users.student);

  for (const path of ['/', '/dersler', '/haberler', '/gizlilik']) {
    await page.goto(path);
    const actions = page.locator('.site-header__actions');
    await expect(actions.getByRole('link', { name: 'Hesabım' })).toHaveAttribute('href', '/hesabim');
    await expect(actions.getByRole('button', { name: 'Çıkış' })).toBeVisible();
    await expect(actions.locator('a[href="/giris"], a[href="/kayit"]')).toHaveCount(0);
    const token = await actions.locator('form.logout-form input[name="_csrf_token"]').inputValue();
    expect(token.length).toBeGreaterThanOrEqual(24);
    await expect(actions.locator('form.logout-form')).toHaveAttribute('action', '/cikis');
    await expect(actions.locator('form.logout-form')).toHaveAttribute('method', 'post');
  }
  await page.goto('/dersler');
  await shot(page, info, 'public-nav', 'dersler-signed-in', '1280');

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/dersler');
  const { drawer } = await openDrawer(page);
  await expect(drawer.getByRole('link', { name: 'Hesabım' })).toBeVisible();
  await expect(drawer.getByRole('link', { name: 'Giriş Yap' })).toHaveCount(0);
  await shot(page, info, 'public-nav', 'dersler-signed-in-menu-open', '390');
  await drawer.getByRole('button', { name: 'Çıkış' }).click();
  await page.waitForURL('/');
  await expect(page.locator('.site-header__actions a[href="/giris"]')).toHaveCount(1);
  await expect(page.locator('.site-header__actions form.logout-form')).toHaveCount(0);
  const privatePage = await page.goto('/hesabim');
  expect(privatePage?.url() ?? page.url()).toContain('/giris');
  await desktop.close();
});
