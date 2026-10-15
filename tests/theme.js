/* Focused appearance checks; Playwright is a development-only dependency.
 * Every API request is intercepted with synthetic bootstrap data. These tests
 * do not log in, write to MySQL, send messages, or contact payment providers.
 * No test authentication shortcut is added to the application.
 */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const base = (process.env.DEZHAWO_THEME_QA_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const output = process.env.DEZHAWO_THEME_QA_OUTPUT || '/tmp/dezhawo-theme-tests';
const errors = [];
const unexpectedRequests = [];
const results = [];
fs.mkdirSync(output, {recursive: true});

function pass(name) {
  results.push(name);
  console.log('PASS ' + name);
}

function bootstrap(staff = false) {
  const permissions = ['products.view', 'products.edit', 'inventory.view', 'finance.view'];
  const owner = {id: 'theme-fixture-owner', name: 'مدیر آزمایشی', phone: '09990000000', roleId: 'theme-fixture-role', permissions};
  return {
    configured: true,
    products: [{
      id: 'theme-fixture-product', title: 'قاب آزمایشی', category: 'case', art: 'case',
      price: 150000, cost: 100000, enabled: true, description: 'نمونه برای بررسی ظاهر سایت.',
      variants: [{id: 'theme-fixture-variant', name: 'مشکی', color: '#222222', stock: 10, available: 10, enabled: true}]
    }],
    shipping: [], cart: [{productId: 'theme-fixture-product', variantId: 'theme-fixture-variant', quantity: 1}],
    reservation: null, orders: [], movements: [], tickets: [],
    currentUser: staff ? owner : null, users: staff ? [owner] : [],
    roles: staff ? [{id: owner.roleId, name: 'نقش آزمایشی', permissions}] : [],
    capabilities: {smsConfigured: false, paymentConfigured: false},
    settings: {brand: 'DEZHAWO', tagline: 'دژاوو؛ انتخابی که می‌ماند.', footerText: 'نمونهٔ بررسی ظاهر سایت.'}
  };
}

async function context(browser, options = {}, staff = false) {
  const ctx = await browser.newContext({reducedMotion: 'reduce', ...options});
  await ctx.route('**/api.php?**', async route => {
    const request = route.request();
    const action = new URL(request.url()).searchParams.get('action');
    if (request.method() !== 'GET' || action !== 'bootstrap') {
      unexpectedRequests.push(request.method() + ' ' + action);
      await route.abort();
      return;
    }
    await route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ok: true, csrf: 'synthetic-theme-test', data: bootstrap(staff)})
    });
  });
  ctx.on('page', page => page.on('pageerror', error => errors.push(error.message)));
  return ctx;
}

async function open(ctx) {
  const page = await ctx.newPage();
  await page.goto(base + '/index.php', {waitUntil: 'networkidle'});
  await page.waitForFunction(() => window.DZ?.state.configured === true);
  return page;
}

async function appearance(page, mode, scheme) {
  await page.waitForFunction(({mode, scheme}) =>
    window.DezhawoTheme?.getMode() === mode &&
    window.DezhawoTheme.getTheme() === scheme &&
    getComputedStyle(document.documentElement).colorScheme === scheme,
  {mode, scheme});
}

function trigger(page) {
  return page.locator('[data-theme-trigger]:visible').first();
}

async function choose(page, mode) {
  await trigger(page).click();
  await page.locator(`[data-theme-choice="${mode}"]:visible`).click();
}

async function focusedChoice(page, mode) {
  await page.waitForFunction(mode => document.activeElement?.dataset.themeChoice === mode, mode);
}

async function withinViewport(page, label) {
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, label + ': horizontal overflow');
}

async function cartBadgeContrast(page) {
  // The cart quantity is small text over a fixed brand color; inheriting the
  // dark-theme foreground here can make the quantity unreadable.
  const ratio = await page.locator('.cart-count').evaluate(element => {
    const style = getComputedStyle(element);
    const rgb = value => value.match(/[\d.]+/g).slice(0, 3).map(Number);
    const luminance = values => values.map(value => {
      const channel = value / 255;
      return channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
    }).reduce((sum, channel, index) => sum + channel * [.2126, .7152, .0722][index], 0);
    const foreground = luminance(rgb(style.color));
    const background = luminance(rgb(style.backgroundColor));
    return (Math.max(foreground, background) + .05) / (Math.min(foreground, background) + .05);
  });
  assert.ok(ratio >= 4.5, 'Cart quantity contrast must be at least 4.5:1; got ' + ratio.toFixed(2));
}

async function systemPreference(browser) {
  for (const os of ['light', 'dark']) {
    const ctx = await context(browser, {colorScheme: os});
    try {
      const page = await open(ctx);
      await appearance(page, 'system', os);
      const changed = os === 'dark' ? 'light' : 'dark';
      await page.emulateMedia({colorScheme: changed});
      await appearance(page, 'system', changed);
      pass('First visit follows ' + os + ' system appearance and tracks changes');
    } finally { await ctx.close(); }
  }
}

async function persistentChoice(browser) {
  const ctx = await context(browser, {colorScheme: 'dark'});
  try {
    const page = await open(ctx);
    for (const mode of ['light', 'dark']) {
      await choose(page, mode);
      await appearance(page, mode, mode);
      await page.reload({waitUntil: 'networkidle'});
      await appearance(page, mode, mode);
      await page.emulateMedia({colorScheme: mode === 'dark' ? 'light' : 'dark'});
      await appearance(page, mode, mode);
    }
    pass('Manual light and dark choices survive reload and override the system');

    const second = await open(ctx);
    await appearance(second, 'dark', 'dark');
    await choose(page, 'light');
    await appearance(second, 'light', 'light');
    await choose(second, 'system');
    await appearance(page, 'system', 'light');
    await page.emulateMedia({colorScheme: 'dark'});
    await appearance(page, 'system', 'dark');
    pass('Tabs share the preference; returning to system resumes live updates');
  } finally { await ctx.close(); }
}

async function unavailableStorage(browser) {
  const ctx = await context(browser, {colorScheme: 'dark'});
  await ctx.addInitScript(() => {
    for (const method of ['getItem', 'setItem', 'removeItem']) {
      Storage.prototype[method] = () => { throw new DOMException('Storage unavailable in this test', 'SecurityError'); };
    }
  });
  try {
    const page = await open(ctx);
    await appearance(page, 'system', 'dark');
    await choose(page, 'light');
    await appearance(page, 'light', 'light');
    await choose(page, 'system');
    await page.emulateMedia({colorScheme: 'light'});
    await appearance(page, 'system', 'light');
    pass('Blocked browser storage does not break rendering or appearance controls');
  } finally { await ctx.close(); }
}

async function keyboardAndLayout(browser) {
  for (const width of [320, 390, 1280]) {
    const ctx = await context(browser, {colorScheme: 'dark', viewport: {width, height: 900}}, true);
    try {
      const page = await open(ctx);
      await page.evaluate(() => document.fonts.ready);
      await appearance(page, 'system', 'dark');
      await withinViewport(page, 'Store ' + width);
      await cartBadgeContrast(page);

      const button = trigger(page);
      await button.focus();
      await page.keyboard.press('Enter');
      await focusedChoice(page, 'system');
      await page.keyboard.press('ArrowDown');
      await focusedChoice(page, 'light');
      await page.keyboard.press('Enter');
      await appearance(page, 'light', 'light');
      assert.equal(await button.evaluate(element => document.activeElement === element), true, 'Selection restores trigger focus');
      await page.keyboard.press('ArrowDown');
      await focusedChoice(page, 'light');
      await page.keyboard.press('End');
      await focusedChoice(page, 'dark');
      await page.keyboard.press('ArrowDown');
      await focusedChoice(page, 'system');
      await page.keyboard.press('ArrowUp');
      await focusedChoice(page, 'dark');
      await page.keyboard.press('Home');
      await focusedChoice(page, 'system');
      const bounds = await page.locator('.theme-menu').boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width, 'Appearance menu stays inside viewport');
      await page.keyboard.press('Escape');
      assert.equal(await button.getAttribute('aria-expanded'), 'false');
      assert.equal(await button.evaluate(element => document.activeElement === element), true, 'Escape restores trigger focus');
      await button.click();
      await page.keyboard.press('Tab');
      assert.equal(await button.getAttribute('aria-expanded'), 'false');
      await button.click();
      await page.locator('#heroTitle').click();
      assert.equal(await button.getAttribute('aria-expanded'), 'false');

      await choose(page, 'dark');
      await page.locator('[data-action="product"]').first().click();
      await withinViewport(page, 'Product dialog ' + width);
      await page.locator('[data-action="close-modal"]').click();
      await page.locator('[data-action="cart"]').first().click();
      await withinViewport(page, 'Cart ' + width);
      await page.locator('[data-action="close-cart"]').click();
      await page.screenshot({path: path.join(output, 'store-dark-' + width + '.png')});

      // A synthetic staff bootstrap verifies the real admin rendering, with
      // no auth request or database change. Only theme UI actions are taken.
      await page.locator('[data-action="admin"]').last().click();
      await page.locator('#adminView').waitFor({state: 'visible'});
      await withinViewport(page, 'Administration ' + width);
      await choose(page, 'light');
      await appearance(page, 'light', 'light');
      await choose(page, 'system');
      await appearance(page, 'system', 'dark');
      await page.screenshot({path: path.join(output, 'admin-dark-' + width + '.png')});
      pass('Keyboard, focus, menu placement, store/dialog/admin controls at ' + width + 'px');
    } finally { await ctx.close(); }
  }
}

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true});
  try {
    await systemPreference(browser);
    await persistentChoice(browser);
    await unavailableStorage(browser);
    await keyboardAndLayout(browser);
    assert.deepEqual(unexpectedRequests, [], 'Appearance tests must never make API mutations');
    assert.deepEqual(errors, [], 'No uncaught JavaScript errors');
    pass('No API mutations or uncaught JavaScript errors');
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({passed: results, errors, unexpectedRequests}, null, 2));
  }
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
