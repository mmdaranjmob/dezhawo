/* Real Chromium coverage for the POS customer's previous purchase count.
 * Loopback and disposable *_qa database only; run sequentially with other
 * database-changing QA. Private login fixtures never enter the repository.
 */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const base = (process.env.DEZHAWO_QA_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const secretsPath = process.env.DEZHAWO_QA_SECRETS;
const outboxPath = process.env.DEZHAWO_QA_OUTBOX;
assert.ok(secretsPath && outboxPath, 'Provide the private disposable QA fixture paths.');
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'POS history browser QA requires loopback.');
const fixture = JSON.parse(fs.readFileSync(secretsPath, 'utf8'));
assert.ok(fixture.db_name?.endsWith('_qa'), 'POS history browser QA requires a disposable *_qa database.');
function sql(statement) {
  const result = spawnSync('docker', ['exec', '-i', process.env.DEZHAWO_QA_DB_CONTAINER || 'dezhawo-qa-db', 'sh', '-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'], {input:statement, encoding:'utf8'});
  assert.equal(result.status, 0, 'Disposable QA operation failed; private details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'), fixture.db_name, 'The QA database does not match the private fixture.');
const prefix = 'poshistoryui_' + Date.now().toString(36) + crypto.randomBytes(3).toString('hex');
const productId = prefix + '_product', variantId = prefix + '_variant', zeroUserId = prefix + '_zero';
const phone = () => '09' + Array.from({length:9}, () => crypto.randomInt(10)).join('');
const historyPhone = phone(), zeroPhone = phone(), newPhone = phone(), racePhoneA = phone(), racePhoneB = phone(), errorPhone = phone();
assert.equal(new Set([historyPhone, zeroPhone, newPhone, racePhoneA, racePhoneB, errorPhone]).size, 6);
const customer = {firstName:'نام صندوق‌دار', lastName:'بدون بازنویسی', address:'آدرس اختیاری آزمون تاریخچه'};
const ascii = value => String(value).replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
const persian = value => String(value).replace(/[0-9]/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
const isLookup = request => new URL(request.url()).searchParams.get('action') === 'pos.customerLookup';
const form = page => page.locator('[data-admin-form="pos-sale"]');
const history = page => page.locator('[data-pos-customer-history]');
let checks = 0;
function pass(label) { checks++; console.log('PASS ' + label); }
async function signal(promise, label) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error('Timed out waiting for ' + label)), 10000);
    })]);
  } finally { clearTimeout(timer); }
}
function otp(phoneNumber) {
  const entries = fs.readdirSync(outboxPath).filter(name => name.endsWith('.json')).map(name => {
    const file = path.join(outboxPath, name);
    try { return {...JSON.parse(fs.readFileSync(file, 'utf8')), time:fs.statSync(file).mtimeMs}; }
    catch (error) { if (error.code === 'EACCES') return null; throw error; }
  }).filter(entry => entry && entry.phone === phoneNumber).sort((a, b) => b.time - a.time);
  assert.ok(entries.length, 'The private QA outbox should contain a fresh owner challenge.');
  return entries[0].code;
}
async function login(page) {
  await page.goto(base + '/index.php', {waitUntil:'networkidle'});
  await page.waitForFunction(() => DZ.state.configured === true);
  await page.locator('.profile-btn[data-action="account"]').click();
  await page.locator('#loginPhoneForm [name="phone"]').fill(fixture.owner_phone);
  await page.locator('#loginPhoneForm [type="submit"]').click();
  await page.locator('#loginCodeForm').waitFor();
  await page.locator('#loginCodeForm [name="code"]').fill(otp(fixture.owner_phone));
  await page.locator('#loginCodeForm [type="submit"]').click();
  await page.locator('#modalContent [data-action="admin"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
async function ready(page, count) {
  await page.waitForFunction(expected => {
    const element = document.querySelector('[data-pos-customer-history]');
    const digits = value => value.replace(/[۰-۹]/g, digit => '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[٠-٩]/g, digit => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
    return element?.dataset.state === 'ready' && new RegExp('تعداد خریدهای قبلی:\\s*' + expected + '(?![0-9])').test(digits(element.textContent));
  }, count);
  assert.equal(await history(page).isVisible(), true, 'A completed lookup should be visible.');
  assert.equal(await history(page).getAttribute('role'), 'status', 'History needs a status announcement.');
  assert.equal(await history(page).getAttribute('aria-live'), 'polite', 'History should announce updates politely.');
}
async function lookup(page, entered, canonical, count) {
  const response = page.waitForResponse(response => isLookup(response.request()) && response.request().postDataJSON()?.phone === canonical);
  await form(page).locator('[name="phone"]').fill(entered);
  const payload = await (await response).json();
  assert.equal(payload.ok, true);
  assert.equal(payload.data.phone, canonical);
  assert.equal(payload.data.purchaseCount, count);
  await ready(page, count);
  return payload.data;
}
async function assertDraft(page) {
  for (const [name, value] of Object.entries(customer)) {
    assert.equal(await form(page).locator(`[name="${name}"]`).inputValue(), value, 'Lookup overwrote customer ' + name);
  }
}
async function addProduct(page) {
  const add = page.locator('[data-admin-form="pos-add"]');
  await add.locator('[name="productId"]').selectOption(productId);
  await add.locator('[name="variantId"]').selectOption(variantId);
  await add.locator('[name="quantity"]').fill('1');
  await add.locator('[type="submit"]').click();
}

(async () => {
  let browser;
  const releases = [];
  try {
    sql('DELETE FROM rate_limits;');
    browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true});
    const context = await browser.newContext({viewport:{width:1440, height:1000}, reducedMotion:'reduce'});
    const page = await context.newPage();
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (isLookup(request)) requests.push(request.postDataJSON()); });
    await login(page);
    const actorId = await page.evaluate(() => DZ.state.currentUser.id);
    assert.match(actorId, /^[a-zA-Z0-9_-]+$/);
    sql(`INSERT INTO users(id,name,phone,role_id,enabled) VALUES('${zeroUserId}','مشتری ثبت‌شده بدون خرید','${zeroPhone}','customer',1);`);
    await page.evaluate(async data => DZ.saveProduct({id:data.productId, title:data.productId, category:'case', price:125000,
      cost:70000, description:'کالای مستقل آزمون تاریخچه مشتری', enabled:true,
      variants:[{id:data.variantId, name:'مشکی', color:'#222222', stock:12, enabled:true}]}), {productId, variantId});
    const samples = [
      ['pos','completed','paid'], ['online','shipped','paid'], ['online','cancelled','paid'],
      ['online','awaiting_payment','unpaid'], ['online','payment_review','paid']
    ];
    for (const [index, [type, status, paymentStatus]] of samples.entries()) {
      const orderId = prefix + '_history_' + index;
      sql(`INSERT INTO orders(id,number,type,user_id,customer_name,customer_phone,status,payment_status,subtotal,total,payment)
        VALUES('${orderId}','${orderId}','${type}','${actorId}','نام قبلی مشتری','${historyPhone}','${status}','${paymentStatus}',875000,875000,'card');
        INSERT INTO order_items(order_id,product_id,variant_id,title,variant_name,category,quantity,unit_price,unit_cost)
        VALUES('${orderId}','${productId}','${variantId}','کالای تاریخچه','مشکی','case',7,125000,70000);`);
    }
    await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="pos"]').click();
    assert.equal(await history(page).isVisible(), false, 'A blank phone should not show history.');
    for (const [name, value] of Object.entries(customer)) await form(page).locator(`[name="${name}"]`).fill(value);
    for (const value of ['09', historyPhone.slice(0, 10), '02112345678']) {
      await form(page).locator('[name="phone"]').fill(value);
      await page.waitForTimeout(550);
      assert.equal(await history(page).isVisible(), false, 'Invalid or incomplete phone should hide history.');
    }
    assert.equal(requests.length, 0, 'Incomplete or invalid phone reached the lookup API.');
    pass('blank, incomplete and landline inputs hide purchase history and send no lookup');

    const previousRequests = requests.length;
    const localEquivalent = '+98' + historyPhone.slice(1);
    const response = page.waitForResponse(response => isLookup(response.request()) && response.request().postDataJSON()?.phone === historyPhone);
    await form(page).locator('[name="phone"]').fill(historyPhone);
    await page.waitForTimeout(50);
    await form(page).locator('[name="phone"]').fill(persian(localEquivalent));
    const actual = await (await response).json();
    assert.deepEqual(actual.data, {phone:historyPhone, exists:true, purchaseCount:2});
    await ready(page, 2);
    assert.equal(requests.length, previousRequests + 1, 'Rapid valid edits were not debounced.');
    assert.match(await history(page).innerText(), /حضوری/);
    assert.match(await history(page).innerText(), /آنلاین|اینترنتی/);
    await assertDraft(page);
    pass('rapid Persian/international mobile input sends one canonical lookup and counts paid POS plus online invoices, excluding cancelled or incomplete orders');

    await addProduct(page); await ready(page, 2); await assertDraft(page);
    await page.locator('[data-admin-action="pos-plus"]').click(); await ready(page, 2); await assertDraft(page);
    await page.locator('[data-admin-action="pos-minus"]').click(); await ready(page, 2); await assertDraft(page);
    await page.evaluate(() => DZ.refresh()); await ready(page, 2); await assertDraft(page);
    assert.equal(await form(page).locator('[name="phone"]').inputValue(), persian(localEquivalent));
    pass('purchase count and typed identity survive product and quantity changes and a server refresh without autofilling names');

    const registered = await lookup(page, zeroPhone, zeroPhone, 0);
    assert.equal(registered.exists, true);
    const unknown = await lookup(page, newPhone, newPhone, 0);
    assert.equal(unknown.exists, false);
    assert.match(await history(page).innerText(), /مشتری جدید/);
    assert.equal(sql(`SELECT COUNT(*) FROM users WHERE phone='${newPhone}';`), '0', 'Looking up a new phone created an account.');
    await assertDraft(page);
    pass('registered customers without purchases and unknown new customers both show zero without creating an account');

    let releaseA, releaseB, requestedA, requestedB;
    const heldA = new Promise(resolve => { releaseA = resolve; releases.push(resolve); });
    const heldB = new Promise(resolve => { releaseB = resolve; releases.push(resolve); });
    const arrivedA = new Promise(resolve => { requestedA = resolve; });
    const arrivedB = new Promise(resolve => { requestedB = resolve; });
    const raceRoute = async route => {
      const value = route.request().postDataJSON()?.phone;
      if (![racePhoneA, racePhoneB].includes(value)) return route.continue();
      if (value === racePhoneA) { requestedA(); await heldA; }
      else { requestedB(); await heldB; }
      await route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({ok:true, data:{phone:value, exists:value === racePhoneA, purchaseCount:value === racePhoneA ? 41 : 0}})});
    };
    await page.route('**/api.php?action=pos.customerLookup', raceRoute);
    await form(page).locator('[name="phone"]').fill(racePhoneA); await signal(arrivedA, 'first phone lookup');
    await form(page).locator('[name="phone"]').fill(racePhoneB);
    releaseA(); await signal(arrivedB, 'second phone lookup');
    assert.equal(await history(page).getAttribute('data-state'), 'loading');
    assert.doesNotMatch(ascii(await history(page).innerText()), /41/, 'Old phone response was shown for the current customer.');
    releaseB(); await ready(page, 0); await assertDraft(page);
    await page.unroute('**/api.php?action=pos.customerLookup', raceRoute);
    pass('a delayed response for an earlier phone cannot replace the current customer history');

    await page.route('**/api.php?action=pos.customerLookup', route => route.fulfill({status:422, contentType:'application/json',
      body:JSON.stringify({ok:false, error:'آزمون خطای قابل تلاش دوباره'})}), {times:1});
    await form(page).locator('[name="phone"]').fill(errorPhone);
    await page.waitForFunction(() => document.querySelector('[data-pos-customer-history]')?.dataset.state === 'error');
    assert.match(await history(page).innerText(), /خطای قابل تلاش دوباره/);
    await assertDraft(page);
    const retried = page.waitForResponse(response => isLookup(response.request()) && response.request().postDataJSON()?.phone === errorPhone);
    await page.locator('[data-admin-action="pos-customer-retry"]').click();
    assert.equal((await (await retried).json()).data.purchaseCount, 0);
    await ready(page, 0); await assertDraft(page);
    pass('lookup failure is readable beside the phone and retry recovers without losing invoice fields');

    await lookup(page, historyPhone, historyPhone, 2);
    await form(page).locator('[type="submit"]').click();
    await page.locator('#modalContent .admin-order-info').waitFor();
    await page.locator('#modalDialog > [data-action="close-modal"]').click();
    await page.locator('#modalDialog').waitFor({state:'hidden'});
    for (const name of ['firstName', 'lastName', 'phone', 'address']) {
      assert.equal(await form(page).locator(`[name="${name}"]`).inputValue(), '', 'Successful sale retained ' + name);
    }
    assert.equal(await history(page).isVisible(), false, 'Successful sale left the previous customer count visible.');
    await lookup(page, historyPhone, historyPhone, 3);
    assert.equal(sql(`SELECT COUNT(*) FROM orders WHERE customer_phone='${historyPhone}' AND payment_status='paid' AND status IN('paid','processing','shipped','completed');`), '3');
    assert.equal(sql(`SELECT stock FROM product_variants WHERE id='${variantId}';`), '11');
    pass('successful sale clears history for the next customer and a fresh lookup includes the newly paid invoice exactly once');

    for (const theme of ['light', 'dark']) {
      await page.setViewportSize({width:375, height:812});
      await page.evaluate(value => document.documentElement.dataset.theme = value, theme);
      await ready(page, 3);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Mobile history causes horizontal overflow.');
      const box = await history(page).boundingBox();
      assert.ok(box && box.x >= 0 && box.x + box.width <= 375 && box.width >= 200 && box.height >= 30, 'History does not fit the mobile ' + theme + ' form.');
    }
    assert.deepEqual(errors, [], 'Browser reported uncaught JavaScript errors.');
    pass('purchase history remains readable on mobile in light and dark themes without uncaught browser errors');
    console.log(`All ${checks} POS customer history browser checks passed.`);
  } finally {
    for (const release of releases) release();
    try { if (browser) await browser.close(); }
    finally {
      const orderIds = sql(`SELECT DISTINCT order_id FROM order_items WHERE product_id='${productId}';`).split('\n').filter(Boolean);
      for (const id of orderIds) {
        assert.match(id, /^[a-zA-Z0-9_-]+$/);
        sql(`DELETE FROM audit_logs WHERE target_id='${id}';DELETE FROM orders WHERE id='${id}';`);
      }
      sql(`DELETE FROM stock_movements WHERE product_id='${productId}';DELETE FROM audit_logs WHERE target_id IN('${productId}','${variantId}','${zeroUserId}');DELETE FROM products WHERE id='${productId}';DELETE FROM users WHERE id='${zeroUserId}';DELETE FROM rate_limits;`);
    }
  }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
