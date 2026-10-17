/* Real Chromium coverage for merchant photo persistence and product galleries.
 * Run sequentially against disposable loopback QA only. Private configuration
 * and OTP fixtures stay outside the repository; all generated rows are removed.
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
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'Product gallery QA requires loopback.');
const fixture = JSON.parse(fs.readFileSync(secretsPath, 'utf8'));
assert.ok(fixture.db_name?.endsWith('_qa'), 'Product gallery QA requires a disposable *_qa database.');
function sql(statement) {
  const result = spawnSync('docker', ['exec', '-i', process.env.DEZHAWO_QA_DB_CONTAINER || 'dezhawo-qa-db', 'sh', '-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'], {input:statement, encoding:'utf8'});
  assert.equal(result.status, 0, 'Disposable QA query failed; private details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'), fixture.db_name, 'The QA database must match its private fixture.');
const prefix = 'galleryui_' + Date.now().toString(36) + crypto.randomBytes(3).toString('hex');
const title = prefix + ' قاب اصلی';
const description = ('قاب با کیفیت برای استفادهٔ روزمره\nخط دوم: محافظت از دوربین و بدنه\n<script>window.galleryDescriptionExecuted=true</script>\n' + 'شرح کامل سازگاری و ویژگی‌های فنی محصول. '.repeat(55)).trim();
const products = [], categories = [], roles = [], users = [], phones = [], uploads = new Set();
const errors = [];
let checks = 0;
const pass = label => { checks++; console.log('PASS ' + label); };
const form = page => page.locator('[data-admin-form="product"]');
const modal = page => page.locator('#modalDialog');
const main = scope => scope.locator('[data-gallery-display] img.product-photo');
const gallery = scope => scope.locator('[data-product-gallery]');
const thumbnails = scope => scope.locator('[data-action="gallery-image"]');

function otp(phone) {
  const entries = fs.readdirSync(outboxPath).filter(name => name.endsWith('.json')).map(name => {
    const file = path.join(outboxPath, name);
    try { return {...JSON.parse(fs.readFileSync(file, 'utf8')), time:fs.statSync(file).mtimeMs}; }
    catch (error) { if (error.code === 'EACCES') return null; throw error; }
  }).filter(entry => entry && entry.phone === phone).sort((a, b) => b.time - a.time);
  assert.ok(entries.length, 'The private QA outbox should contain a fresh challenge.');
  return entries[0].code;
}
async function loaded(page, target = '/index.php') {
  await page.goto(base + target, {waitUntil:'networkidle'});
  await page.waitForFunction(() => DZ.state.configured === true && !DZ.state.loading);
}
async function login(page, phone = fixture.owner_phone) {
  await page.locator('.profile-btn[data-action="account"]').click();
  let target = page.locator('#loginPhoneForm');
  await target.locator('[name="phone"]').fill(phone); await target.locator('[type="submit"]').click();
  target = page.locator('#loginCodeForm'); await target.waitFor();
  await target.locator('[name="code"]').fill(otp(phone)); await target.locator('[type="submit"]').click();
  await page.waitForFunction(value => DZ.state.currentUser?.phone === value, phone);
  await page.locator('#modalContent [data-action="admin"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
async function closed(page) { await page.waitForFunction(() => !document.getElementById('modalDialog').open); }
async function close(page) { await page.locator('#modalDialog > [data-action="close-modal"]').click(); await closed(page); }
async function imageLoaded(locator) {
  await locator.waitFor({state:'visible'}); await locator.evaluate(image => image.decode());
  assert.equal(await locator.evaluate(image => image.complete && image.naturalWidth > 0), true, 'Photo must decode as actual image bytes.');
}
async function edit(page, id) {
  await page.evaluate(() => UI.go('admin'));
  await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="products"]').click();
  await page.locator(`[data-admin-action="product-edit"][data-id="${id}"]`).click();
  await form(page).waitFor();
}
async function save(page) { await form(page).locator('[type="submit"]').click(); await closed(page); }
async function assertDraft(page) {
  assert.equal(await form(page).locator('[name="title"]').inputValue(), title);
  assert.equal(await form(page).locator('[name="description"]').inputValue(), description);
  assert.equal(await form(page).locator('.admin-product-photo-preview img.product-photo').count(), 1);
  assert.equal(await form(page).locator('.admin-product-gallery-item').count(), 2);
}
async function canvases(browser) {
  const context = await browser.newContext(), page = await context.newPage();
  const encoded = await page.evaluate(() => {
    return ['#e9624d', '#69a7d4', '#d8b846'].map((color, index) => {
      const canvas = document.createElement('canvas'); canvas.width = 240; canvas.height = 320;
      const ctx = canvas.getContext('2d'); ctx.fillStyle = '#f6f3e8'; ctx.fillRect(0, 0, 240, 320);
      ctx.fillStyle = color; ctx.beginPath(); ctx.roundRect(55, 25, 130, 270, 24); ctx.fill();
      ctx.fillStyle = '#222'; ctx.fillRect(65, 40, 38 + index * 8, 55); ctx.fillStyle = '#fff';
      ctx.font = '24px sans-serif'; ctx.fillText(String(index + 1), 108, 180);
      const mime = index === 1 ? 'image/jpeg' : index === 2 ? 'image/webp' : 'image/png';
      return {mime, encoded:canvas.toDataURL(mime, .9).split(',')[1]};
    });
  });
  await context.close();
  return encoded.map((image, index) => ({name:`gallery-${index}.${index === 1 ? 'jpg' : index === 2 ? 'webp' : 'png'}`,
    mimeType:image.mime, buffer:Buffer.from(image.encoded, 'base64')}));
}
async function createFixtures(page) {
  const root = await page.evaluate(name => DZ.saveCategory({name, productType:'physical', enabled:true}), prefix + ' ریشه'); categories.push(root.id);
  const leaf = await page.evaluate(value => DZ.saveCategory({name:value.name, parentId:value.parentId, enabled:true}), {name:prefix + ' قاب', parentId:root.id}); categories.push(leaf.id);
  const sibling = await page.evaluate(value => DZ.saveCategory({name:value.name, parentId:value.parentId, enabled:true}), {name:prefix + ' هم‌خانواده', parentId:root.id}); categories.push(sibling.id);
  const hidden = await page.evaluate(value => DZ.saveCategory({name:value.name, parentId:value.parentId, enabled:true}), {name:prefix + ' دسته پنهان', parentId:root.id}); categories.push(hidden.id);
  const configs = [
    ['related', leaf.id, true, true], ['sibling', sibling.id, true, true], ['disabled', leaf.id, false, true],
    ['no_variant', leaf.id, true, false], ['hidden_category', hidden.id, true, true]
  ];
  const extra = {};
  for (const [key, categoryId, enabled, variantEnabled] of configs) {
    const id = prefix + '_' + key; products.push(id);
    await page.evaluate(value => DZ.saveProduct(value), {id, title:prefix + ' ' + key, categoryId, enabled, price:120000,
      description:'محصول مستقل آزمون مرتبط', variants:[{id:id + '_v', name:'مشکی', color:'#222222', stock:4, enabled:variantEnabled}]});
    extra[key] = id;
  }
  await page.evaluate(value => DZ.saveCategory({...value, enabled:false}), {id:hidden.id, name:prefix + ' دسته پنهان', parentId:root.id});
  return {leaf:leaf.id, ...extra};
}
async function relatedIds(scope) {
  return scope.locator('.product-related-grid a[href*="product.php?id="]').evaluateAll(links => links.map(link => new URL(link.href).searchParams.get('id')));
}
function checkRelated(ids, productId, extra) {
  assert.ok(ids.length > 0 && ids.length <= 6, 'Related shelf should show up to six actual public products.');
  assert.equal(ids[0], extra.related, 'The same leaf category should rank first.');
  assert.ok(ids.includes(extra.sibling), 'A shared parent category should be considered.');
  for (const hidden of [productId, extra.disabled, extra.no_variant, extra.hidden_category]) assert.ok(!ids.includes(hidden), 'Related shelf leaked current or hidden product.');
}

(async () => {
  let browser, productId;
  try {
    sql('DELETE FROM rate_limits;');
    browser = await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless:true});
    const context = await browser.newContext({viewport:{width:1440, height:1000}, reducedMotion:'reduce'});
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    const photos = await canvases(browser);
    await loaded(page); await login(page);
    if (await page.evaluate(() => DZ.state.databaseUpgrade?.required === true)) {
      await page.evaluate(() => DZ.upgradeDatabase());
      assert.equal(await page.evaluate(() => !!DZ.state.databaseUpgrade?.required), false, 'Owner database upgrade should finish before gallery QA.');
    }
    const extra = await createFixtures(page);
    await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="products"]').click();
    await page.locator('[data-admin-action="product-new"]').click();
    await form(page).locator('[name="title"]').fill(title);
    await form(page).locator('[name="categoryId"]').selectOption(extra.leaf);
    await form(page).locator('[name="price"]').fill('240000');
    await form(page).locator('[name="cost"]').fill('120000');
    await form(page).locator('[name="description"]').fill(description);
    await form(page).locator('[name="variantStock"]').fill('7');
    await form(page).locator('#adminProductImage').setInputFiles(photos[0]);
    await form(page).locator('#adminProductGallery').setInputFiles(photos.slice(1));
    await assertDraft(page);
    await form(page).locator('[data-admin-action="variant-add"]').click();
    await assertDraft(page);
    let second = form(page).locator('[data-variant-row]').nth(1);
    await second.locator('[name="variantName"]').fill('آبی'); await second.locator('[name="variantStock"]').fill('3');
    assert.equal(await form(page).locator('.admin-product-photo-preview img').evaluate(image => image.src.startsWith('blob:')), true);
    pass('main photo, two gallery files, description and identity survive adding a new color before saving');

    let uploadPosts = 0, savePosts = 0;
    const responseJobs = [];
    page.on('request', request => {
      const action = new URL(request.url()).searchParams.get('action');
      if (action === 'product.imageUpload') uploadPosts++;
      if (action === 'product.save') savePosts++;
    });
    page.on('response', response => {
      if (new URL(response.url()).searchParams.get('action') !== 'product.imageUpload' || !response.ok()) return;
      responseJobs.push(response.json().then(payload => { if (payload.data?.imageUrl) uploads.add(payload.data.imageUrl); }));
    });
    await second.locator('[name="variantName"]').fill('');
    await form(page).locator('[type="submit"]').click();
    await form(page).locator('[data-admin-error]').waitFor({state:'visible'});
    assert.equal(uploadPosts, 0); assert.equal(savePosts, 0); await assertDraft(page);
    await second.locator('[name="variantName"]').fill('آبی');
    pass('invalid product data is reported inline before any photo upload or product save');

    await page.route('**/api.php?action=product.imageUpload', route => route.fulfill({status:503, contentType:'application/json',
      body:JSON.stringify({ok:false, error:'خطای آزمایشی بارگذاری؛ دوباره ذخیره کنید.'})}), {times:1});
    await form(page).locator('[type="submit"]').click();
    await form(page).locator('[data-admin-error]').filter({hasText:'خطای آزمایشی بارگذاری'}).waitFor({state:'visible'});
    assert.equal(uploadPosts, 1); assert.equal(savePosts, 0); await assertDraft(page);
    assert.equal(await form(page).locator('[type="submit"]').isDisabled(), false);
    pass('failed upload keeps selected photos and typed product data available for retry');

    await page.route('**/api.php?action=product.save', route => route.fulfill({status:422, contentType:'application/json',
      body:JSON.stringify({ok:false, error:'خطای آزمایشی ذخیره؛ عکس‌ها حفظ شدند.'})}), {times:1});
    await form(page).locator('[type="submit"]').click();
    await form(page).locator('[data-admin-error]').filter({hasText:'خطای آزمایشی ذخیره'}).waitFor({state:'visible'});
    await Promise.all(responseJobs);
    assert.equal(uploadPosts, 4); assert.equal(uploads.size, 3); assert.equal(savePosts, 1); await assertDraft(page);
    let lostResponseIdentity;
    await page.route('**/api.php?action=product.save', async route => {
      const response = await route.fetch(); assert.equal(response.status(), 200);
      lostResponseIdentity = (await response.json()).data;
      await route.abort();
    }, {times:1});
    await form(page).locator('[type="submit"]').click();
    await form(page).locator('[data-admin-error]').filter({hasText:'ارتباط'}).waitFor({state:'visible'});
    productId = await form(page).getAttribute('data-id'); assert.equal(productId, lostResponseIdentity.id); products.push(productId);
    const lostResponseVariants = await form(page).locator('[name="variantId"]').evaluateAll(inputs => inputs.map(input => input.value));
    assert.deepEqual(lostResponseVariants, lostResponseIdentity.variantIds);
    assert.equal(sql(`SELECT COUNT(*) FROM products WHERE id='${productId}';`), '1');
    assert.equal(sql(`SELECT COUNT(*) FROM product_variants WHERE product_id='${productId}';`), '2');
    await assertDraft(page);
    await page.route('**/api.php?action=bootstrap', route => route.fulfill({status:503, contentType:'application/json',
      body:JSON.stringify({ok:false, error:'خطای آزمایشی دریافت اطلاعات تازه پس از ثبت محصول.'})}), {times:1});
    await form(page).locator('[type="submit"]').click();
    await form(page).locator('[data-admin-error]').filter({hasText:'محصول ثبت شد'}).waitFor({state:'visible'});
    assert.equal(await form(page).getAttribute('data-id'), productId);
    const committedVariants = await form(page).locator('[name="variantId"]').evaluateAll(inputs => inputs.map(input => input.value));
    assert.ok(committedVariants.length === 2 && committedVariants.every(Boolean), 'Committed product identity must survive a failed follow-up bootstrap.');
    assert.equal(sql(`SELECT COUNT(*) FROM products WHERE id='${productId}';`), '1');
    await save(page);
    assert.equal(uploadPosts, 4, 'Retrying product save must reuse already uploaded photos.');
    let saved = await page.evaluate(value => DZ.state.products.find(product => product.title === value), title);
    assert.equal(saved?.id, productId, 'Retrying after committed save must update the same product.');
    assert.deepEqual(saved.variants.map(variant => variant.id).sort(), committedVariants.sort(), 'A refresh failure must not duplicate colors.');
    assert.equal(sql(`SELECT COUNT(*) FROM products WHERE title='${title}';`), '1');
    assert.equal(saved.description, description); assert.equal(saved.galleryUrls.length, 2);
    assert.equal(new Set([saved.imageUrl, ...saved.galleryUrls]).size, 3);
    assert.deepEqual(saved.variants.map(variant => variant.stock).sort((a, b) => a - b), [3, 7]);
    let savedUrls = [saved.imageUrl, ...saved.galleryUrls];
    for (let index = 0; index < savedUrls.length; index++) {
      assert.match(savedUrls[index], /^\/uploads\/products\/[a-f0-9]{48}\.(png|jpg|webp)$/);
      const response = await context.request.get(base + savedUrls[index]); assert.equal(response.status(), 200);
      assert.deepEqual(await response.body(), photos[index].buffer, 'Photo URL should serve the exact uploaded bytes.');
    }
    pass('retries after save failure, lost committed response and failed follow-up refresh reuse photos and product/color identities without duplicates');

    await edit(page, productId);
    assert.equal(await form(page).locator('[name="description"]').inputValue(), description);
    assert.equal(await form(page).locator('.admin-product-gallery-item').count(), 2);
    await imageLoaded(form(page).locator('.admin-product-photo-preview img.product-photo'));
    assert.equal(new URL(await form(page).locator('.admin-product-photo-preview img').getAttribute('src'), base).pathname, saved.imageUrl);
    await form(page).locator('[data-admin-action="product-gallery-main"][data-index="0"]').click();
    await save(page);
    let switched = await page.evaluate(id => DZ.getProduct(id), productId);
    assert.equal(switched.imageUrl, savedUrls[1]); assert.deepEqual(switched.galleryUrls, [savedUrls[0], savedUrls[2]]);
    assert.equal(uploadPosts, 4, 'Choosing an existing gallery image as main should not upload again.');
    await edit(page, productId);
    await form(page).locator('[data-admin-action="product-gallery-main"][data-index="0"]').click();
    await form(page).locator('[data-admin-action="product-gallery-remove"][data-index="1"]').click();
    assert.equal(await form(page).locator('.admin-product-gallery-item').count(), 1);
    await form(page).locator('#adminProductGallery').setInputFiles(photos[2]);
    await save(page); await Promise.all(responseJobs);
    saved = await page.evaluate(id => DZ.getProduct(id), productId);
    assert.equal(saved.imageUrl, savedUrls[0]); assert.equal(saved.galleryUrls[0], savedUrls[1]);
    assert.notEqual(saved.galleryUrls[1], savedUrls[2]); assert.equal(uploadPosts, 5);
    assert.equal((await context.request.get(base + savedUrls[2])).status(), 200, 'Removing a gallery reference must preserve a possibly shared photo.');
    savedUrls = [saved.imageUrl, ...saved.galleryUrls];
    assert.deepEqual(await (await context.request.get(base + savedUrls[2])).body(), photos[2].buffer);
    assert.deepEqual(saved.variants.map(variant => variant.stock).sort((a, b) => a - b), [3, 7]);
    pass('any gallery photo can become the main image, and removing or adding photos preserves color inventory and existing shared files');
    await page.reload({waitUntil:'networkidle'}); await page.waitForFunction(() => DZ.state.configured && !DZ.state.loading);
    saved = await page.evaluate(id => DZ.getProduct(id), productId);
    assert.deepEqual([saved.imageUrl, ...saved.galleryUrls], savedUrls); assert.equal(saved.description, description);
    assert.equal(sql(`SELECT COUNT(*) FROM products WHERE id='${productId}' AND image_url IS NOT NULL AND gallery_json IS NOT NULL;`), '1');
    pass('reopening the editor and reloading from the database retain all three photos and multiline description');

    const customerContext = await browser.newContext({viewport:{width:390, height:844}, reducedMotion:'reduce'});
    const customer = await customerContext.newPage(); customer.on('pageerror', error => errors.push(error.message));
    await loaded(customer); await customer.locator('[data-action="search"]').click();
    await customer.locator('#catalogSearch').fill(title);
    const card = customer.locator(`[data-action="product"][data-id="${productId}"]`).first();
    await imageLoaded(card.locator('img.product-photo'));
    assert.equal(new URL(await card.locator('img').getAttribute('src'), base).pathname, savedUrls[0]);
    await card.click(); await imageLoaded(main(modal(customer))); assert.equal(await thumbnails(modal(customer)).count(), 3);
    assert.equal(await customer.locator('.product-description').innerText(), description);
    assert.equal(await customer.evaluate(() => window.galleryDescriptionExecuted), undefined);
    checkRelated(await relatedIds(modal(customer)), productId, extra);
    await modal(customer).locator('[data-action="product-plus"]').click();
    const quantity = await customer.locator('#productQuantity').innerText();
    await thumbnails(modal(customer)).nth(2).click(); await imageLoaded(main(modal(customer)));
    assert.equal(new URL(await main(modal(customer)).getAttribute('src'), base).pathname, savedUrls[2]);
    assert.equal(await customer.locator('#productQuantity').innerText(), quantity, 'Photo switching must preserve quantity.');
    assert.equal(await thumbnails(modal(customer)).nth(2).getAttribute('aria-current'), 'true');
    await modal(customer).locator('[data-action="variant"]').nth(1).click();
    assert.equal(new URL(await main(modal(customer)).getAttribute('src'), base).pathname, savedUrls[2], 'Changing color must preserve selected gallery image.');
    pass('a separate public session sees the persisted main photo, interactive gallery, safe multiline description and eligible related products');

    await loaded(customer, '/product.php?id=' + encodeURIComponent(productId));
    await imageLoaded(main(customer.locator('.product-page-detail')));
    assert.equal(await customer.locator('.product-page-description').innerText(), description);
    assert.equal(await thumbnails(customer.locator('.product-page-detail')).count(), 3);
    const ssrRelated = await customer.locator('#productGrid .product-title').evaluateAll(links => links.map(link => new URL(link.href).searchParams.get('id')));
    checkRelated(ssrRelated, productId, extra);
    for (const theme of ['light', 'dark']) {
      await customer.evaluate(value => document.documentElement.dataset.theme = value, theme);
      await thumbnails(customer.locator('.product-page-detail')).nth(1).click(); await imageLoaded(main(customer.locator('.product-page-detail')));
      assert.equal(new URL(await main(customer.locator('.product-page-detail')).getAttribute('src'), base).pathname, savedUrls[1]);
      assert.equal(await customer.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'Mobile gallery causes horizontal overflow in ' + theme + '.');
      for (const thumb of await thumbnails(customer.locator('.product-page-detail')).all()) {
        const box = await thumb.boundingBox(); assert.ok(box && box.width >= 40 && box.height >= 40, 'Gallery thumbnail should be a usable touch target.');
      }
    }
    const noScript = await browser.newContext({javaScriptEnabled:false, viewport:{width:390, height:844}}), staticPage = await noScript.newPage();
    await staticPage.goto(base + '/product.php?id=' + encodeURIComponent(productId), {waitUntil:'networkidle'});
    await imageLoaded(main(staticPage.locator('.product-page-detail')));
    assert.equal(await staticPage.locator('.product-page-description').innerText(), description);
    const ld = JSON.parse(await staticPage.locator('script[type="application/ld+json"]').first().textContent());
    const schemaProduct = ld['@graph']?.find(item => item['@type'] === 'Product') || ld;
    assert.ok(Array.isArray(schemaProduct.image) && schemaProduct.image.length === 3, 'Search metadata should include every persisted photo.');
    await noScript.close();
    pass('standalone product page renders photos and related products before JavaScript and its gallery works on mobile in light and dark modes');

    const phone = '09' + Array.from({length:9}, () => crypto.randomInt(10)).join(''); phones.push(phone);
    const restricted = await page.evaluate(async data => {
      const role = await DZ.saveRole({name:data.name, permissions:['products.view', 'products.edit']});
      const user = await DZ.createUser({name:'ویرایشگر آزمون گالری', phone:data.phone, roleId:role.id}); return {role, user};
    }, {name:prefix + ' editor', phone}); roles.push(restricted.role.id); users.push(restricted.user.id);
    const restrictedContext = await browser.newContext({viewport:{width:1280, height:900}}), editor = await restrictedContext.newPage();
    editor.on('pageerror', error => errors.push(error.message)); await loaded(editor); await login(editor, phone);
    await editor.locator('.admin-sidebar [data-admin-action="tab"][data-tab="products"]').click();
    assert.equal(await editor.locator(`[data-admin-action="product-delete"][data-id="${productId}"]`).count(), 0);
    const denied = await editor.evaluate(async id => {
      try { await DZ.deleteProduct(id); return false; } catch (error) { return !!error.message; }
    }, productId);
    assert.equal(denied, true); assert.equal(sql(`SELECT enabled FROM products WHERE id='${productId}';`), '1');
    await restrictedContext.close();
    pass('product editing permission alone exposes no delete button and cannot delete through the API');

    const variantId = saved.variants[0].id;
    const invoice = await page.evaluate(data => DZ.sellPOS({lines:[{productId:data.id, variantId:data.variantId, quantity:1}], payment:'cash',
      customer:{firstName:'خریدار', lastName:'آزمون گالری', phone:'09120000000'}, address:null}), {id:productId, variantId});
    await page.evaluate(() => UI.go('admin'));
    await page.locator('.admin-sidebar [data-admin-action="tab"][data-tab="products"]').click();
    await page.locator(`[data-admin-action="product-delete"][data-id="${productId}"]`).click();
    await page.locator('[data-admin-form="product-delete"]').waitFor();
    await close(page);
    assert.equal(sql(`SELECT enabled FROM products WHERE id='${productId}';`), '1', 'Cancelling deletion should preserve publication.');
    await page.locator(`[data-admin-action="product-delete"][data-id="${productId}"]`).click();
    await page.locator('[data-admin-form="product-delete"] [type="submit"]').click(); await closed(page);
    await page.waitForFunction(id => DZ.getProduct(id)?.enabled === false, productId);
    assert.equal(sql(`SELECT COUNT(*) FROM products WHERE id='${productId}' AND enabled=0;`), '1');
    assert.equal(sql(`SELECT COUNT(*) FROM order_items WHERE order_id='${invoice.id}' AND product_id='${productId}';`), '1');
    await loaded(customer); assert.equal(await customer.locator(`[data-action="product"][data-id="${productId}"]`).count(), 0);
    const hiddenResponse = await customerContext.request.get(base + '/product.php?id=' + encodeURIComponent(productId));
    assert.equal(hiddenResponse.status(), 404);
    assert.equal(sql(`SELECT image_url IS NOT NULL AND gallery_json IS NOT NULL FROM products WHERE id='${productId}';`), '1');
    pass('explicit confirmed deletion removes a product from the storefront while preserving its photos and existing invoice history');
    assert.deepEqual(errors, [], 'Uncaught browser errors should be absent.');
    pass('all merchant and customer gallery flows complete without uncaught JavaScript errors');
    console.log(`All ${checks} product gallery browser checks passed.`);
  } finally {
    try { if (browser) await browser.close(); }
    finally {
      if (products.length) {
        const ids = products.map(id => "'" + id + "'").join(',');
        const orders = sql('SELECT DISTINCT order_id FROM order_items WHERE product_id IN (' + ids + ');').split('\n').filter(Boolean);
        for (const id of orders) {
          assert.ok(/^[a-zA-Z0-9_-]+$/.test(id)); sql(`DELETE FROM audit_logs WHERE target_id='${id}';DELETE FROM orders WHERE id='${id}';`);
        }
        sql('DELETE FROM stock_movements WHERE product_id IN (' + ids + ');DELETE FROM audit_logs WHERE target_id IN (' + ids + ');DELETE FROM products WHERE id IN (' + ids + ');');
      }
      for (const id of categories.reverse()) sql(`DELETE FROM audit_logs WHERE target_id='${id}';DELETE FROM categories WHERE id='${id}';`);
      for (const id of users) sql(`DELETE FROM audit_logs WHERE user_id='${id}' OR target_id='${id}';DELETE FROM users WHERE id='${id}';`);
      for (const id of roles) sql(`DELETE FROM audit_logs WHERE target_id='${id}';DELETE FROM roles WHERE id='${id}';`);
      for (const phone of phones) sql(`DELETE FROM otp_challenges WHERE phone='${phone}';`);
      for (const url of uploads) {
        const name = url.split('/').pop(); assert.ok(/^[a-f0-9]{48}\.(png|jpg|webp)$/.test(name));
        sql(`DELETE FROM audit_logs WHERE target_id='${name}';`);
        const target = path.join(path.dirname(secretsPath), 'product-uploads', name);
        if (fs.existsSync(target)) fs.unlinkSync(target);
      }
      sql('DELETE FROM rate_limits;');
    }
  }
})().catch(error => { console.error(error.stack || error.message); process.exitCode = 1; });
