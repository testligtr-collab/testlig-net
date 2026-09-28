import { expect, test } from '@playwright/test';
import { assertClean, assertLayout, openMobile, shot, VIEWPORTS, watch, installVideoAbort } from './support';

const pages = [
  { path: '/', indexable: true, shot: 'home' },
  { path: '/gizlilik', indexable: true, shot: 'gizlilik' },
  { path: '/kullanim-kosullari', indexable: true, shot: null },
  { path: '/cerez-politikasi', indexable: true, shot: null },
  { path: '/cocuk-ve-veli-bilgilendirmesi', indexable: true, shot: null },
  { path: '/giris', indexable: false, shot: 'giris' },
  { path: '/kayit', indexable: false, shot: null },
  { path: '/kayit/ogrenci', indexable: false, shot: null },
];

test.beforeEach(async ({ page }) => {
  installVideoAbort(page);
  await watch(page);
});

test('public pages, menus, index policy and layout', async ({ page }, info) => {
  for (const viewport of VIEWPORTS) {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    for (const entry of pages) {
      const response = await page.goto(entry.path);
      expect(response?.status()).toBe(200);
      const robots = response?.headers()['x-robots-tag'] ?? '';
      if (entry.indexable) {
        expect(robots).not.toContain('noindex');
      } else {
        expect(robots).toContain('noindex');
      }
      await assertLayout(page);
      await assertClean(page);
      const inline = await page.locator('script:not([src]):not([nonce]), style:not([nonce])').count();
      expect(inline).toBe(0);
      if (viewport.width < 1024) {
        await openMobile(page);
        await page.keyboard.press('Escape');
        const toggle = page.getByRole('button', { name: 'Menüyü aç veya kapat' });
        if (await toggle.isVisible()) {
          await expect(toggle).toHaveAttribute('aria-expanded', 'false');
          const box = await toggle.boundingBox();
          expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }
      }
      if (entry.shot && (entry.shot !== 'gizlilik' || entry.path === '/gizlilik')) {
        if (entry.shot === 'home' || entry.shot === 'giris' || entry.shot === 'gizlilik') {
          await shot(page, info, 'public', entry.shot, viewport.name);
        }
      }
    }
  }

  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Gizlilik' }).first()).toHaveAttribute('href', '/gizlilik');
  await expect(page.getByRole('link', { name: 'Kullanım Koşulları' }).first()).toHaveAttribute('href', '/kullanim-kosullari');
  await expect(page.getByRole('link', { name: 'Çerez Politikası' }).first()).toHaveAttribute('href', '/cerez-politikasi');
  await expect(page.getByRole('link', { name: 'Çocuk ve Veli Bilgilendirmesi' }).first()).toHaveAttribute('href', '/cocuk-ve-veli-bilgilendirmesi');

  await page.goto('/giris');
  expect(await unlabeled(page)).toBe(0);
  await page.goto('/kayit');
  expect(await unlabeled(page)).toBe(0);
});

async function unlabeled(page: import('@playwright/test').Page): Promise<number> {
  return page.evaluate(() => Array.from(document.querySelectorAll('input, select, textarea')).filter((node) => {
    if (!(node instanceof HTMLElement)) {
      return false;
    }
    if (node.getAttribute('type') === 'hidden') {
      return false;
    }
    if (node.getAttribute('aria-label') || node.getAttribute('aria-labelledby')) {
      return false;
    }
    const id = node.getAttribute('id');
    if (id && document.querySelector(`label[for="${CSS.escape(id)}"]`)) {
      return false;
    }
    return node.closest('label') === null;
  }).length);
}
