#!/usr/bin/env node
import { chromium } from 'playwright';

const BASE = 'https://eduardoyauriluna.com';
const ROUTES = [
  '/', '/es/',
  '/about/', '/es/perfil/',
  '/research/', '/es/investigacion/',
  '/cv/', '/es/cv/',
  '/insights/', '/es/notas/',
  '/contact/', '/es/contacto/',
];

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 1,
    isMobile: true,
    hasTouch: true,
    reducedMotion: 'reduce',
    userAgent: 'EduardoResearch-Phase6-Mobile-Audit/1.0',
  });

  for (const route of ROUTES) {
    const page = await context.newPage();
    const response = await page.goto(new URL(route, BASE).href, {
      waitUntil: 'domcontentloaded',
      timeout: 45_000,
    });
    assert(response && response.status() === 200, `${route}: expected HTTP 200, got ${response?.status()}`);

    const state = await page.evaluate(() => {
      const main = document.querySelector('main#main');
      const h1 = main?.querySelector('h1');
      const footer = document.querySelector('footer[role="contentinfo"]');
      const root = document.documentElement;
      const body = document.body;
      const mainStyle = main ? getComputedStyle(main) : null;
      const h1Style = h1 ? getComputedStyle(h1) : null;
      return {
        viewport: window.innerWidth,
        rootClientWidth: root.clientWidth,
        rootScrollWidth: root.scrollWidth,
        bodyScrollWidth: body.scrollWidth,
        mainVisible: !!main && mainStyle?.display !== 'none' && mainStyle?.visibility !== 'hidden',
        h1Visible: !!h1 && h1Style?.display !== 'none' && h1Style?.visibility !== 'hidden' && h1.textContent.trim().length > 0,
        footerPresent: !!footer,
      };
    });

    assert(state.viewport === 390, `${route}: unexpected viewport width ${state.viewport}`);
    assert(state.rootScrollWidth <= state.rootClientWidth + 2, `${route}: horizontal root overflow ${state.rootScrollWidth}px > ${state.rootClientWidth}px`);
    assert(state.bodyScrollWidth <= state.rootClientWidth + 2, `${route}: horizontal body overflow ${state.bodyScrollWidth}px > ${state.rootClientWidth}px`);
    assert(state.mainVisible, `${route}: main content is not visibly rendered`);
    assert(state.h1Visible, `${route}: H1 is not visibly rendered`);
    assert(state.footerPresent, `${route}: footer is missing`);

    const skip = page.locator('a.screen-reader-text[href="#main"]');
    assert(await skip.count() === 1, `${route}: expected one skip link`);
    await page.keyboard.press('Tab');
    assert(await skip.evaluate((node) => node === document.activeElement), `${route}: first keyboard focus is not the skip link`);
    assert(await skip.isVisible(), `${route}: skip link is not visible when focused`);

    const toggle = page.locator('[data-research-menu-toggle]');
    assert(await toggle.count() === 1, `${route}: mobile menu toggle missing`);
    assert(await toggle.isVisible(), `${route}: mobile menu toggle not visible at 390px`);
    await toggle.focus();
    await page.keyboard.press('Enter');
    assert((await toggle.getAttribute('aria-expanded')) === 'true', `${route}: mobile menu is not keyboard-operable`);

    const nav = page.locator('#research-primary-nav');
    assert(await nav.isVisible(), `${route}: primary nav not visible after opening mobile menu`);
    const navLinks = nav.locator('a');
    assert(await navLinks.count() >= 5, `${route}: primary nav exposes fewer than five researcher links`);

    console.log(`MOBILE ${route} -> 200, no overflow, main/H1 visible, skip link focusable, menu keyboard-operable`);
    await page.close();
  }

  await context.close();
  console.log('PASS: core EN/ES researcher surfaces render cleanly at 390x844 with keyboard-operable mobile navigation');
} finally {
  await browser.close();
}
