/* Optional browser integration checks against an isolated testing installation.
   No build step is needed for the site. Playwright is a test-only dependency.
   DEZHAWO_QA_URL, DEZHAWO_QA_SECRETS and DEZHAWO_QA_OUTBOX must refer to private test fixtures. */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const base = process.env.DEZHAWO_QA_URL || 'http://127.0.0.1:8080';
const secretsPath = process.env.DEZHAWO_QA_SECRETS;
const outboxPath = process.env.DEZHAWO_QA_OUTBOX;
if (!secretsPath || !outboxPath) throw new Error('Provide private testing fixture paths in DEZHAWO_QA_SECRETS and DEZHAWO_QA_OUTBOX.');
const fixture = JSON.parse(fs.readFileSync(secretsPath,'utf8'));
const suffix = Date.now().toString(36);
const productTitle = 'uiqa_' + suffix;
const customerPhone = '0999' + String(Date.now()).slice(-7);
const errors = [];
const pass = name => console.log('PASS ' + name);

async function loaded(page) {
  await page.goto(base + '/index.php',{waitUntil:'networkidle'});
  await page.waitForFunction(() => DZ.state.configured === true);
}
async function tab(page, name) {
  await page.locator(`[data-admin-action="tab"][data-tab="${name}"]`).click();
}
async function waitModalClosed(page) { await page.locator('#modalDialog').waitFor({state:'hidden'}); }
async function staffLogin(page) {
  await page.locator('[data-action="admin"]').last().click();
  const f = page.locator('#staffLoginForm');
  await f.locator('[name="phone"]').fill(fixture.owner_phone);
  await f.locator('[name="password"]').fill(fixture.owner_password);
  await f.locator('[type="submit"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
function testCode(phone) {
  const entries = fs.readdirSync(outboxPath).filter(x => x.endsWith('.json')).map(name => {
    const file = path.join(outboxPath,name);
    try{return {...JSON.parse(fs.readFileSync(file,'utf8')),time:fs.statSync(file).mtimeMs};}catch(err){if(err.code==='EACCES')return null;throw err;}
  }).filter(x => x && x.phone === phone).sort((a,b) => b.time - a.time);
  assert.ok(entries.length,'Actual private testing SMS outbox should contain a challenge.');
  return entries[0].code;
}

(async () => {
  const browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true});
  try {
    for (const [name,width,height] of [['desktop',1440,1000],['laptop',1024,768],['tablet',768,1024],['mobile',390,844],['landscape',844,390]]) {
      const context = await browser.newContext({viewport:{width,height},reducedMotion:'reduce'});
      const page = await context.newPage(); page.on('pageerror',e => errors.push(e.message));
      await loaded(page);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,name+' horizontal overflow');
      assert.equal(await page.locator('#runtimeStatus').isVisible(),false);
      await page.locator('[data-action="admin"]').last().click();
      assert.equal(await page.locator('#staffLoginForm').isVisible(),true);
      assert.equal(await page.locator('#adminView').isVisible(),false);
      await page.locator('[data-action="close-modal"]').click();
      assert.equal(await page.evaluate(() => localStorage.getItem('dezhawo-demo-v1')),null);
      await context.close();pass(name+' responsive layout and staff authentication gate');
    }

    const staffContext = await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    const staff = await staffContext.newPage();staff.on('pageerror',e => errors.push(e.message));
    await loaded(staff);await staffLogin(staff);pass('actual staff password authentication');
    await tab(staff,'products');await staff.locator('[data-admin-action="product-new"]').click();
    let f = staff.locator('[data-admin-form="product"]');
    await f.locator('[name="title"]').fill(productTitle);
    await f.locator('[name="price"]').fill('120000');
    await f.locator('[name="cost"]').fill('70000');
    await f.locator('[name="description"]').fill('محصول مستقل آزمون مرورگر');
    await f.locator('[name="variantStock"]').fill('5');
    await staff.locator('[data-admin-action="variant-add"]').click();
    f = staff.locator('[data-admin-form="product"]');
    const second = f.locator('[data-variant-row]').last();
    await second.locator('[name="variantName"]').fill('آبی');
    await second.locator('[name="variantColor"]').fill('#3366aa');
    await second.locator('[name="variantStock"]').fill('3');
    await second.locator('[name="variantPrice"]').fill('140000');
    await f.locator('[type="submit"]').click();await waitModalClosed(staff);
    const product = await staff.evaluate(title => DZ.state.products.find(p => p.title === title),productTitle);
    assert.ok(product?.id);assert.equal(product.variants.length,2);const blackVariant=product.variants.find(v=>v.name==='مشکی'),blueVariant=product.variants.find(v=>v.name==='آبی');
    assert.equal(blueVariant.price,140000);pass('one server product with independently priced colors');

    await tab(staff,'pos');f = staff.locator('[data-admin-form="pos-add"]');
    await f.locator('[name="productId"]').selectOption(product.id);
    await f.locator('[name="variantId"]').selectOption(blackVariant.id);
    await f.locator('[type="submit"]').click();
    await staff.locator('[data-admin-form="pos-sale"] [type="submit"]').click();
    await staff.waitForFunction(id => DZ.getProduct(id).variants.find(v=>v.name==='مشکی').stock === 4,product.id);
    if(await staff.locator('#modalDialog').isVisible())await staff.locator('[data-action="close-modal"]').click();
    pass('POS immediately deducts shared server inventory');

    const customerContext = await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});
    const customer = await customerContext.newPage();customer.on('pageerror',e => errors.push(e.message));
    await loaded(customer);
    assert.equal(await customer.evaluate(id => DZ.getProduct(id).variants.find(v=>v.name==='مشکی').stock,product.id),4);
    if(await customer.locator('#showMoreBtn').isVisible())await customer.locator('#showMoreBtn').click();
    await customer.locator(`[data-action="product"][data-id="${product.id}"]`).first().click();
    await customer.locator(`[data-action="variant"][data-variant="${blueVariant.id}"]`).click();
    assert.match(await customer.locator('.product-modal-price').textContent(),/۱۴۰/);
    await customer.locator('[data-action="add-cart"]').click();
    await customer.waitForFunction(() => DZ.cartCount() === 1);
    assert.equal(await customer.evaluate(() => DZ.cartTotal()),140000);
    await customer.locator('[data-action="close-cart"]').click();
    await loaded(customer);assert.equal(await customer.evaluate(() => DZ.cartCount()),1);
    pass('other device sees POS stock and server cart survives reload');

    await customer.locator('[data-action="account"]').first().click();
    f = customer.locator('#loginPhoneForm');await f.locator('[name="phone"]').fill(customerPhone);
    await f.locator('[type="submit"]').click();await customer.locator('#loginCodeForm').waitFor();
    f = customer.locator('#loginCodeForm');await f.locator('[name="code"]').fill(testCode(customerPhone));
    await f.locator('[type="submit"]').click();
    await customer.waitForFunction(phone => DZ.state.currentUser?.phone === phone,customerPhone);
    assert.equal(await customer.evaluate(() => DZ.state.currentUser.permissions.length),0);
    await customer.locator('[data-action="account-tab"][data-tab="tickets"]').click();
    await customer.locator('[data-action="ticket-new"]').click();
    f = customer.locator('#ticketForm');await f.locator('[name="subject"]').fill(productTitle+' پشتیبانی');
    await f.locator('[name="message"]').fill('راهنمای انتخاب رنگ');await f.locator('[type="submit"]').click();
    await customer.waitForFunction(title => DZ.state.tickets.some(t => t.subject === title),productTitle+' پشتیبانی');
    const ticketId = await customer.evaluate(title => DZ.state.tickets.find(t => t.subject === title).id,productTitle+' پشتیبانی');
    await customer.locator(`[data-action="ticket-view"][data-id="${ticketId}"]`).click();
    f = customer.locator('#ticketReplyForm');await f.locator('[name="message"]').fill('پیام دوم مشتری');
    await f.locator('[type="submit"]').click();
    await customer.waitForFunction(id => DZ.state.tickets.find(t => t.id === id)?.messages.length === 2,ticketId);
    pass('real server OTP authentication and ticket conversation');

    await staff.evaluate(() => DZ.refresh());await tab(staff,'tickets');
    await staff.locator(`[data-admin-action="ticket"][data-id="${ticketId}"]`).click();
    f = staff.locator('[data-admin-form="ticket"]');await f.locator('[name="message"]').fill('پاسخ پشتیبانی فروشگاه');
    await f.locator('[name="status"]').selectOption('waiting');await f.locator('[type="submit"]').click();
    await staff.waitForFunction(id => DZ.state.tickets.find(t => t.id === id)?.messages.length === 3,ticketId);
    await customer.evaluate(() => DZ.refresh());
    assert.equal(await customer.evaluate(id => DZ.state.tickets.find(t => t.id === id).messages.at(-1).staff,ticketId),true);
    pass('staff reply is stored and visible from separate customer session');

    if(process.env.DEZHAWO_QA_PAYMENTS==='1'){
      await customer.locator('[data-action="close-modal"]').click();
      await customer.locator('[data-action="cart"]').click();
      await customer.locator('[data-action="checkout"]').click();
      f=customer.locator('#checkoutForm');await f.waitFor();
      await f.locator('[name="name"]').fill('مشتری آزمون مرورگر');
      await f.locator('[name="province"]').fill('تهران');await f.locator('[name="city"]').fill('تهران');
      await f.locator('[name="address"]').fill('خیابان آزمون، پلاک ۱');await f.locator('[name="postalCode"]').fill('1234567890');
      let intercept;
      const redirected=new Promise(resolve=>{intercept=resolve;});
      await customer.route('**/payment-callback.php?**',async route=>{intercept(route.request().url());await route.abort();});
      await f.locator('[type="submit"]').click();await redirected;
      await loaded(customer);
      assert.equal(await customer.evaluate(()=>DZ.state.reservation?.status),'payment_pending');
      const pendingId=await customer.evaluate(()=>DZ.state.reservation.orderId);
      await customer.locator('[data-action="cart"]').click();
      assert.equal(await customer.locator('[data-action="cart-plus"]').isDisabled(),true);
      assert.equal(await customer.locator('[data-action="cart-remove"]').isDisabled(),true);
      await customer.unroute('**/payment-callback.php?**');
      await customer.locator('[data-action="checkout"]').click();
      await customer.waitForURL('**/payment-callback.php?**');
      await customer.waitForLoadState('networkidle');
      assert.equal(await customer.locator('h1').textContent(),'پرداخت تأیید شد.');
      await loaded(customer);
      assert.equal(await customer.evaluate(id=>DZ.state.orders.find(o=>o.id===id)?.paymentStatus,pendingId),'paid');
      assert.equal(await customer.evaluate(id=>DZ.getProduct(id).variants.find(v=>v.name==='آبی').stock,product.id),2);
      assert.equal(await customer.evaluate(()=>DZ.cartCount()),0);
      pass('pending order locks cart; resume verifies gateway callback and consumes server stock');
    }

    assert.deepEqual(errors,[]);pass('no uncaught JavaScript errors');
    await staffContext.close();await customerContext.close();
  } finally { await browser.close(); }
})().catch(err => { console.error(err.message); process.exitCode = 1; });
