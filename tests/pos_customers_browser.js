/* Customer identity on actual POS invoices, with one intercepted failed request.
 * Run sequentially against disposable loopback QA only. Private OTP fixtures stay
 * outside the repository; all created products, invoices and movements are removed.
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
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'POS customer browser QA requires loopback.');
const fixture = JSON.parse(fs.readFileSync(secretsPath, 'utf8'));
assert.ok(fixture.db_name?.endsWith('_qa'), 'POS customer browser QA requires a disposable *_qa database.');
function sql(statement) {
  const result = spawnSync('docker', ['exec', '-i', process.env.DEZHAWO_QA_DB_CONTAINER || 'dezhawo-qa-db', 'sh', '-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'], {input:statement, encoding:'utf8'});
  assert.equal(result.status, 0, 'Disposable QA operation failed; private details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'), fixture.db_name, 'The QA database does not match the private fixture.');
const prefix = 'poscustomerui_' + Date.now().toString(36) + crypto.randomBytes(3).toString('hex');
const productId = prefix + '_product';
const variantId = prefix + '_variant';
const customer = {firstName:'سارا', lastName:'احمدی', phone:'09' + Array.from({length:9}, () => crypto.randomInt(10)).join('')};
const address = 'تهران، خیابان آزمون، پلاک ۲۱';
const errors = [];
let checks = 0;
function pass(label) { checks++; console.log('PASS ' + label); }
function otp(phone) {
  const entries = fs.readdirSync(outboxPath).filter(name => name.endsWith('.json')).map(name => {
    const file = path.join(outboxPath, name);
    try { return {...JSON.parse(fs.readFileSync(file, 'utf8')), time:fs.statSync(file).mtimeMs}; }
    catch (error) { if (error.code === 'EACCES') return null; throw error; }
  }).filter(entry => entry && entry.phone === phone).sort((a, b) => b.time - a.time);
  assert.ok(entries.length, 'The private QA outbox should contain a fresh owner challenge.');
  return entries[0].code;
}
async function loaded(page) {
  await page.goto(base + '/index.php', {waitUntil:'networkidle'});
  await page.waitForFunction(() => DZ.state.configured === true);
}
async function login(page) {
  await page.locator('.profile-btn[data-action="account"]').click();
  let form = page.locator('#loginPhoneForm');
  await form.locator('[name="phone"]').fill(fixture.owner_phone);
  await form.locator('[type="submit"]').click();
  form = page.locator('#loginCodeForm'); await form.waitFor();
  await form.locator('[name="code"]').fill(otp(fixture.owner_phone));
  await form.locator('[type="submit"]').click();
  await page.locator('#modalContent [data-action="admin"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
const saleForm = page => page.locator('[data-admin-form="pos-sale"]');
async function fillCustomer(page, extra = {}) {
  const form = saleForm(page);
  for (const [key, value] of Object.entries({...customer, address:'', payment:'cash', ...extra})) {
    if (key === 'payment') await form.locator('[name="payment"]').selectOption(value);
    else await form.locator(`[name="${key}"]`).fill(value);
  }
}
async function assertCustomer(page, extra = {}) {
  for (const [key, value] of Object.entries({...customer, address:'', payment:'cash', ...extra})) {
    assert.equal(await saleForm(page).locator(`[name="${key}"]`).inputValue(), value, 'POS draft lost ' + key);
  }
}
async function addProduct(page) {
  const form = page.locator('[data-admin-form="pos-add"]');
  await form.locator('[name="productId"]').selectOption(productId);
  await form.locator('[name="variantId"]').selectOption(variantId);
  await form.locator('[name="quantity"]').fill('1');
  await form.locator('[type="submit"]').click();
}
function inventorySnapshot() {
  return sql(`SELECT stock FROM product_variants WHERE id='${variantId}';`) + '|' +
    sql(`SELECT COUNT(*) FROM stock_movements WHERE product_id='${productId}';`) + '|' +
    sql(`SELECT COUNT(*) FROM order_items WHERE product_id='${productId}';`);
}
async function closeInvoice(page) {
  await page.locator('#modalDialog > [data-action="close-modal"]').click();
  await page.locator('#modalDialog').waitFor({state:'hidden'});
}
async function assertBlankCustomer(page) {
  for (const name of ['firstName', 'lastName', 'phone', 'address']) {
    assert.equal(await saleForm(page).locator(`[name="${name}"]`).inputValue(), '', 'Successful invoice retained customer ' + name);
  }
  assert.equal(await page.locator('.admin-pos-row').count(), 0, 'Successful invoice retained its product lines.');
  assert.equal(await saleForm(page).locator('[type="submit"]').isDisabled(), true, 'An empty next invoice can be submitted.');
}

(async () => {
  let browser;
  let releaseFailure;
  try {
    sql('DELETE FROM rate_limits;');
    browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true});
    const context = await browser.newContext({viewport:{width:1440, height:1000}, reducedMotion:'reduce'});
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await loaded(page); await login(page);
    await page.evaluate(async data => DZ.saveProduct({id:data.productId, title:data.productId, category:'case',
      price:125000, cost:70000, description:'کالای مستقل آزمون مشخصات مشتری فروش حضوری', enabled:true,
      variants:[{id:data.variantId, name:'مشکی', color:'#222222', stock:12, enabled:true}]}), {productId, variantId});
    await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="pos"]').click();
    const form = saleForm(page);
    for (const name of ['firstName', 'lastName', 'phone']) {
      assert.equal(await form.locator(`[name="${name}"]`).getAttribute('required'), '', name + ' should be required.');
    }
    assert.equal(await form.locator('[name="address"]').getAttribute('required'), null, 'Address should be optional.');
    await assertBlankCustomer(page);
    pass('new POS invoice starts with empty required first name, surname and mobile, and optional address');

    let salePosts = 0;
    page.on('request', request => {
      if (new URL(request.url()).searchParams.get('action') === 'pos.sale') salePosts++;
    });
    await addProduct(page);
    const originalInventory = inventorySnapshot();
    for (const name of ['firstName', 'lastName', 'phone']) {
      await fillCustomer(page);
      await saleForm(page).locator(`[name="${name}"]`).fill('');
      await saleForm(page).locator('[type="submit"]').click();
      await saleForm(page).locator('[data-admin-error]').waitFor({state:'visible'});
      assert.ok((await saleForm(page).locator('[data-admin-error]').innerText()).trim(), 'Missing field error should be readable.');
      assert.equal(salePosts, 0, 'A missing identity field reached the POS API.');
      assert.equal(inventorySnapshot(), originalInventory, 'Missing identity changed shared stock or created an invoice.');
    }
    pass('each missing identity field reports inline and creates no request, invoice or inventory movement');

    await fillCustomer(page, {phone:'02112345678'});
    await saleForm(page).locator('[type="submit"]').click();
    await saleForm(page).locator('[data-admin-error]').waitFor({state:'visible'});
    assert.match(await saleForm(page).locator('[data-admin-error]').innerText(), /موبایل|شماره/);
    assert.equal(inventorySnapshot(), originalInventory, 'Invalid mobile changed inventory.');
    assert.equal(await saleForm(page).locator('[name="phone"]').inputValue(), '02112345678');
    pass('a landline is rejected with an inline mobile error while the customer can correct it');

    await fillCustomer(page, {address});
    await addProduct(page); await assertCustomer(page, {address});
    await page.locator('[data-admin-action="pos-plus"]').click(); await assertCustomer(page, {address});
    await page.locator('[data-admin-action="pos-minus"]').click(); await assertCustomer(page, {address});
    await page.evaluate(() => DZ.refresh()); await assertCustomer(page, {address});
    assert.equal(await page.locator('.admin-pos-quantity b').innerText(), '۲');
    assert.equal(inventorySnapshot(), originalInventory, 'Editing an unfinished invoice changed inventory.');
    pass('customer identity, optional address and payment survive product additions, quantity edits and server refresh');

    // Intercept exactly one request so a recoverable server failure can be
    // exercised without creating an extra real invoice or modifying stock.
    const held = new Promise(resolve => { releaseFailure = resolve; });
    await page.route('**/api.php?action=pos.sale', async route => {
      await held;
      await route.fulfill({status:422, contentType:'application/json', body:JSON.stringify({ok:false, error:'خطای موقت آزمون؛ مشخصات مشتری حفظ شد.'})});
    }, {times:1});
    const beforeFailurePosts = salePosts;
    const pendingRequest = page.waitForRequest(request => new URL(request.url()).searchParams.get('action') === 'pos.sale');
    await saleForm(page).locator('[type="submit"]').click();
    await page.waitForFunction(() => document.querySelector('[data-admin-form="pos-sale"]')?.dataset.busy === '1');
    await pendingRequest;
    await page.evaluate(() => Admin.render('pos'));
    assert.equal(await saleForm(page).locator('[type="submit"]').isDisabled(), true, 'Rerender enabled a pending POS submission.');
    await saleForm(page).evaluate(element => { element.requestSubmit(); element.requestSubmit(); });
    await page.waitForTimeout(100);
    assert.equal(salePosts, beforeFailurePosts + 1, 'Duplicate pending POS submissions reached the API.');
    await assertCustomer(page, {address});
    releaseFailure();
    await saleForm(page).locator('[data-admin-error]').filter({hasText:'مشخصات مشتری حفظ شد'}).waitFor({state:'visible'});
    await assertCustomer(page, {address});
    assert.equal(await page.locator('.admin-pos-quantity b').innerText(), '۲');
    assert.equal(await saleForm(page).locator('[type="submit"]').isDisabled(), false);
    assert.equal(inventorySnapshot(), originalInventory, 'Failed POS request changed inventory or created an invoice.');
    pass('pending submission stays locked across rerender, duplicates are blocked, and failure preserves an editable invoice with inline error');

    // Blank address is intentionally valid; identity remains mandatory.
    await saleForm(page).locator('[name="address"]').fill('');
    const previousPosts = salePosts;
    await saleForm(page).locator('[type="submit"]').click();
    await page.locator('#modalContent .admin-order-info').waitFor();
    assert.equal(salePosts, previousPosts + 1);
    const info = await page.locator('#modalContent .admin-order-info').innerText();
    assert.ok(info.includes(customer.firstName + ' ' + customer.lastName) && info.includes(customer.phone), 'Invoice detail lost customer name or mobile.');
    const firstOrder = await page.evaluate(id => DZ.state.orders.find(order => order.type === 'pos' && order.lines.some(line => line.productId === id)), productId);
    assert.ok(firstOrder?.id, 'Successful POS order is absent from server bootstrap.');
    assert.equal(firstOrder.customer.name, customer.firstName + ' ' + customer.lastName);
    assert.equal(firstOrder.customer.phone, customer.phone);
    assert.equal(firstOrder.address, null);
    assert.equal(sql(`SELECT stock FROM product_variants WHERE id='${variantId}';`), '10');
    assert.equal(sql(`SELECT COUNT(*) FROM orders WHERE id='${firstOrder.id}' AND customer_phone='${customer.phone}' AND address_json IS NULL;`), '1');
    await closeInvoice(page); await assertBlankCustomer(page);
    pass('optional blank address saves a paid POS invoice with required customer identity, deducts stock once and clears the next customer draft');

    await addProduct(page); await fillCustomer(page, {address, payment:'card'});
    await saleForm(page).locator('[type="submit"]').click();
    await page.locator('#modalContent .admin-order-info').waitFor();
    assert.ok((await page.locator('#modalContent .admin-order-info').innerText()).includes(address), 'Saved optional address is absent from invoice detail.');
    const orders = await page.evaluate(id => DZ.state.orders.filter(order => order.type === 'pos' && order.lines.some(line => line.productId === id)), productId);
    assert.equal(orders.length, 2, 'Pending or failed submit created an extra invoice.');
    const secondOrder = orders.find(order => order.id !== firstOrder.id);
    assert.deepEqual(secondOrder.address, {line:address});
    assert.equal(secondOrder.customer.name, customer.firstName + ' ' + customer.lastName);
    assert.equal(secondOrder.customer.phone, customer.phone);
    assert.equal(sql(`SELECT stock FROM product_variants WHERE id='${variantId}';`), '9');
    const persisted = JSON.parse(Buffer.from(sql(`SELECT HEX(address_json) FROM orders WHERE id='${secondOrder.id}';`), 'hex').toString('utf8'));
    assert.deepEqual(persisted, {line:address});
    await closeInvoice(page); await assertBlankCustomer(page);
    await page.reload({waitUntil:'networkidle'}); await page.waitForFunction(() => DZ.state.configured === true);
    const persistedCustomer = await page.evaluate(id => DZ.state.orders.find(order => order.id === id)?.customer, secondOrder.id);
    assert.equal(persistedCustomer.name, customer.firstName + ' ' + customer.lastName);
    assert.equal(persistedCustomer.phone, customer.phone);
    pass('optional address and full customer identity persist in database, invoice details and a fresh server bootstrap');

    await page.evaluate(() => UI.go('admin'));
    await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="pos"]').click();
    await fillCustomer(page, {address});
    for (const theme of ['light', 'dark']) {
      await page.setViewportSize({width:375, height:812});
      await page.evaluate(value => document.documentElement.dataset.theme = value, theme);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Mobile POS customer form causes horizontal overflow.');
      for (const name of ['firstName', 'lastName', 'phone', 'address']) {
        const geometry = await saleForm(page).locator(`[name="${name}"]`).evaluate(element => {
          const rect = element.getBoundingClientRect(); return {left:rect.left, right:rect.right, width:rect.width, height:rect.height};
        });
        assert.ok(geometry.left >= 0 && geometry.right <= 375 && geometry.width >= 100 && geometry.height >= 40, 'Mobile ' + theme + ' field does not fit: ' + name);
      }
    }
    assert.deepEqual(errors, [], 'Browser reported uncaught JavaScript errors.');
    pass('required customer fields remain usable on mobile in light and dark themes without uncaught browser errors');
    console.log(`All ${checks} POS customer browser checks passed.`);
  } finally {
    if (releaseFailure) releaseFailure();
    try { if (browser) await browser.close(); }
    finally {
      // Generated IDs are restricted to letters, digits and underscores above.
      const orderIds = sql(`SELECT DISTINCT order_id FROM order_items WHERE product_id='${productId}';`).split('\n').filter(Boolean);
      for (const id of orderIds) {
        assert.ok(/^[a-zA-Z0-9_-]+$/.test(id), 'Fixture order ID is unsafe.');
        sql(`DELETE FROM audit_logs WHERE target_id='${id}';DELETE FROM orders WHERE id='${id}';`);
      }
      sql(`DELETE FROM stock_movements WHERE product_id='${productId}';DELETE FROM audit_logs WHERE target_id IN ('${productId}','${variantId}');DELETE FROM products WHERE id='${productId}';DELETE FROM rate_limits;`);
    }
  }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
