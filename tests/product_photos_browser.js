/* Product photos and the original DEZHAWO appearance.
 * The default run uses intercepted synthetic API data only. To also upload real
 * images to the disposable local QA installation, set DEZHAWO_QA_PHOTOS_LIVE=1
 * and the private fixture paths documented in tests/browser.js. Never run this
 * development-only check against a hosting or production installation.
 */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');

const base = (process.env.DEZHAWO_QA_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const output = process.env.DEZHAWO_PHOTOS_QA_OUTPUT || '/tmp/dezhawo-product-photos-browser';
assert.ok(['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname), 'QA requires a loopback URL.');
fs.mkdirSync(output, {recursive:true});
const errors = [];
const pass = name => console.log('PASS ' + name);
const imagePath = '/uploads/products/' + 'a'.repeat(48) + '.png';
const fixtureProduct = {
  id:'photo-fixture', title:'قاب رنگی با عکس واقعی', category:'case', art:'case',
  price:190000, cost:100000, enabled:true, description:'عکس واقعی محصول، با انتخاب رنگ.', imageUrl:imagePath,
  variants:[{id:'photo-variant',name:'آبی',color:'#4189c4',stock:7,available:7,enabled:true}]
};

function fixtureData() {
  return {configured:true, products:[fixtureProduct,{...fixtureProduct,id:'without-photo',title:'محصول بدون عکس',imageUrl:null,
    variants:[{...fixtureProduct.variants[0],id:'without-photo-variant'}]}], shipping:[],
    currentUser:null,users:[],roles:[],cart:[],reservation:null,orders:[],movements:[],tickets:[],
    paymentMethods:[{id:'digipay',label:'دیجی‌پی',available:true}],
    capabilities:{smsConfigured:false,paymentConfigured:true},
    settings:{brand:'DEZHAWO',tagline:'دژاوو؛ انتخابی که می‌ماند.',footerText:'جزئیات کوچک برای روزهای بهتر.'}};
}

async function photoFixtures(browser) {
  const context = await browser.newContext();
  const page = await context.newPage();
  const encoded = await page.evaluate(() => {
    const canvas=document.createElement('canvas');canvas.width=360;canvas.height=440;
    const c=canvas.getContext('2d');c.fillStyle='#fff4c6';c.fillRect(0,0,360,440);
    c.fillStyle='#227aa8';c.beginPath();c.roundRect(93,36,174,358,28);c.fill();
    c.fillStyle='#122639';c.beginPath();c.roundRect(108,50,67,80,15);c.fill();
    c.fillStyle='#9fd5f7';c.beginPath();c.arc(127,71,11,0,Math.PI*2);c.fill();
    c.beginPath();c.arc(151,106,11,0,Math.PI*2);c.fill();
    return {png:canvas.toDataURL('image/png').split(',')[1],jpeg:canvas.toDataURL('image/jpeg',.9).split(',')[1]};
  });
  await context.close();
  return {png:Buffer.from(encoded.png,'base64'),jpeg:Buffer.from(encoded.jpeg,'base64')};
}

async function loaded(page) {
  await page.goto(base + '/index.php',{waitUntil:'networkidle'});
  await page.waitForFunction(() => DZ.state.configured === true);
}

async function loadedImage(locator) {
  await locator.waitFor({state:'visible'});
  // A laid-out image can still be outside Chromium's lazy-loading threshold,
  // especially at 320px. Exercise the same scroll a customer would perform
  // before waiting for decode, and give a failed image a finite deadline.
  await locator.scrollIntoViewIfNeeded();
  await locator.evaluate(image => new Promise((resolve,reject) => {
    const timer=setTimeout(()=>reject(new Error('Product photo did not decode within 15 seconds.')),15000);
    image.decode().then(()=>{clearTimeout(timer);resolve();},error=>{clearTimeout(timer);reject(error);});
  }));
  assert.equal(await locator.evaluate(image => image.complete && image.naturalWidth > 0),true);
}

async function synthetic(browser, images) {
  for (const [device,width,height] of [['desktop',1440,1000],['tablet',768,1024],['mobile',390,844],['small-mobile',320,720]]) {
    for (const appearance of ['light','dark']) {
      const context = await browser.newContext({viewport:{width,height},colorScheme:appearance,reducedMotion:'reduce'});
      const fixture=fixtureData();
      const mutations=[];
      await context.route('**/api.php?**',async route => {
        const action=new URL(route.request().url()).searchParams.get('action');
        if(action!=='bootstrap') {mutations.push(action);await route.abort();return;}
        await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,csrf:'synthetic-photo-qa',data:fixture})});
      });
      await context.route('**/uploads/products/**',route => route.fulfill({contentType:'image/png',body:images.png}));
      // Exercise the supplied banner's intentional image-failure fallback too.
      await context.route('https://www.mydigipay.com/**',route=>route.abort());
      const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
      await loaded(page);await page.evaluate(()=>document.fonts.ready);
      assert.equal(await page.evaluate(()=>document.documentElement.dataset.theme),appearance);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,device+' overflow');
      assert.match(await page.locator('#heroTitle').textContent(),/جزئیات کوچک،/);
      assert.equal(await page.locator('link[href="assets/luxury.css"],body.luxury-store,.membership').count(),0);
      assert.equal(await page.locator('#categoryGrid .category-card').count(),6);
      assert.ok(await page.locator('#heroMosaic .mosaic-cell').count()>=6,'Original colorful mosaic should remain');
      const palette=await page.evaluate(()=>({paper:getComputedStyle(document.body).backgroundColor,
        accent:getComputedStyle(document.querySelector('#heroTitle .accent')).color}));
      assert.equal(palette.paper,appearance==='light'?'rgb(245, 244, 239)':'rgb(20, 23, 19)');
      assert.equal(palette.accent,appearance==='light'?'rgb(239, 93, 68)':'rgb(245, 122, 98)');
      const product=page.locator('[data-action="product"][data-id="photo-fixture"]').first();
      await loadedImage(product.locator('img.product-photo'));
      assert.equal(await product.locator('svg.product-art').count(),0,'Photo takes priority over artwork');
      const noPhoto=page.locator('[data-action="product"][data-id="without-photo"]').first();
      assert.equal(await noPhoto.locator('img').count(),0);
      assert.match(await noPhoto.textContent(),/عکس|تصویر/,'Missing photo should be stated honestly');
      assert.equal(await page.locator('#installmentBanner').isVisible(),true);
      assert.equal(await page.locator('.installment-card--text').count(),1);
      assert.equal(await page.locator('.installment-card').evaluate(el=>getComputedStyle(el).overflow),'hidden');
      await page.screenshot({path:path.join(output,device+'-'+appearance+'.png'),fullPage:true});
      await product.click();
      await loadedImage(page.locator('.product-modal-art img.product-photo'));
      const source=new URL(await page.locator('.product-modal-art img.product-photo').getAttribute('src'),base);
      assert.equal(source.origin,new URL(base).origin);assert.equal(source.pathname,imagePath);
      assert.deepEqual(mutations,[],'Visual fixture must not mutate the real server');
      await context.close();pass(device+' '+appearance+' original palette, layout and real-photo display');
    }
  }
}

function qaSql(statement) {
  const result=spawnSync('docker',['exec','-i',process.env.DEZHAWO_QA_DB_CONTAINER||'dezhawo-qa-db','sh','-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'],
    {input:statement,encoding:'utf8'});
  assert.equal(result.status,0,'Disposable QA database query failed; private details withheld.');
  return result.stdout.trim();
}

function testCode(phone,outboxPath) {
  const entries=fs.readdirSync(outboxPath).filter(name=>name.endsWith('.json')).map(name=>{
    const file=path.join(outboxPath,name);
    try{return {...JSON.parse(fs.readFileSync(file,'utf8')),time:fs.statSync(file).mtimeMs};}
    catch(error){if(error.code==='EACCES')return null;throw error;}
  }).filter(entry=>entry&&entry.phone===phone).sort((a,b)=>b.time-a.time);
  assert.ok(entries.length,'Private QA SMS outbox should contain a fresh challenge');
  return entries[0].code;
}

async function login(page,phone,outboxPath,management=false) {
  await page.locator('.profile-btn[data-action="account"]').click();
  let form=page.locator('#loginPhoneForm');await form.locator('[name="phone"]').fill(phone);
  await form.locator('[type="submit"]').click();await page.locator('#loginCodeForm').waitFor();
  form=page.locator('#loginCodeForm');await form.locator('[name="code"]').fill(testCode(phone,outboxPath));
  await form.locator('[type="submit"]').click();
  await page.waitForFunction(value=>DZ.state.currentUser?.phone===value,phone);
  if(management){await page.locator('#modalContent [data-action="admin"]').click();await page.locator('#adminView').waitFor({state:'visible'});}
}

async function saveForm(page) {
  await page.locator('[data-admin-form="product"] [type="submit"]').click();
  await page.locator('#modalDialog').waitFor({state:'hidden'});
}

async function live(browser,images) {
  const secretPath=process.env.DEZHAWO_QA_SECRETS,outboxPath=process.env.DEZHAWO_QA_OUTBOX;
  assert.ok(secretPath&&outboxPath,'Live photo QA requires private disposable fixture paths');
  const fixture=JSON.parse(fs.readFileSync(secretPath,'utf8'));
  assert.ok(fixture.db_name?.endsWith('_qa'),'Live photo QA requires a disposable *_qa database');
  assert.equal(qaSql('SELECT DATABASE();'),fixture.db_name);
  qaSql('DELETE FROM rate_limits;');
  const suffix=Date.now().toString(36),title='photoqa_'+suffix;
  const contexts=[];let productId,restrictedUserId;
  const ownerContext=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});contexts.push(ownerContext);
  const owner=await ownerContext.newPage();owner.on('pageerror',error=>errors.push(error.message));
  try {
    await loaded(owner);await login(owner,fixture.owner_phone,outboxPath,true);
    await owner.locator('[data-admin-action="tab"][data-tab="products"]').click();
    await owner.locator('[data-admin-action="product-new"]').click();
    let form=owner.locator('[data-admin-form="product"]');
    await form.locator('[name="title"]').fill(title);await form.locator('[name="price"]').fill('240000');
    await form.locator('[name="cost"]').fill('120000');await form.locator('[name="variantStock"]').fill('7');
    await form.locator('#adminProductImage').setInputFiles({name:'real-product.png',mimeType:'image/png',buffer:images.png});
    await loadedImage(form.locator('.admin-product-photo-preview img.product-photo'));
    assert.match(await form.locator('.admin-product-photo-preview img').getAttribute('src'),/^blob:/);
    await saveForm(owner);
    let product=await owner.evaluate(value=>DZ.state.products.find(p=>p.title===value),title);productId=product.id;
    assert.match(product.imageUrl,/^\/uploads\/products\/[a-f0-9]{48}\.png$/);
    const pngUrl=product.imageUrl,variantId=product.variants[0].id;
    assert.equal(product.variants[0].stock,7);
    const pngResponse=await ownerContext.request.get(base+pngUrl);
    assert.equal(pngResponse.status(),200);assert.match(pngResponse.headers()['content-type'],/^image\/png/);
    assert.deepEqual(await pngResponse.body(),images.png);pass('owner uploads actual PNG and persists its URL with unchanged stock');

    await owner.locator(`[data-admin-action="product-edit"][data-id="${productId}"]`).click();
    form=owner.locator('[data-admin-form="product"]');
    // A sale or inventory correction may happen after this editor was opened.
    // A photo-only save must preserve the newer authoritative stock value.
    await owner.evaluate(data=>DZ.adjustStock(data.productId,data.variantId,2,'ورود موجودی همزمان با ویرایش عکس'),{productId,variantId});
    assert.equal(await form.locator('[name="variantStock"]').inputValue(),'7');
    await form.locator('#adminProductImage').setInputFiles({name:'replacement.jpg',mimeType:'image/jpeg',buffer:images.jpeg});
    await saveForm(owner);product=await owner.evaluate(id=>DZ.getProduct(id),productId);
    assert.match(product.imageUrl,/^\/uploads\/products\/[a-f0-9]{48}\.jpg$/);assert.notEqual(product.imageUrl,pngUrl);
    assert.equal(product.variants[0].stock,9);assert.equal(product.variants[0].id,variantId);
    pass('photo-only save preserves a stock adjustment made after the editor opened');
    const jpegUrl=product.imageUrl;
    const customerContext=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});contexts.push(customerContext);
    const customer=await customerContext.newPage();customer.on('pageerror',error=>errors.push(error.message));
    await loaded(customer);await customer.locator('[data-action="search"]').click();
    await customer.locator('#catalogSearch').fill(title);
    const card=customer.locator(`[data-action="product"][data-id="${productId}"]`).first();
    await loadedImage(card.locator('img.product-photo'));await card.click();
    await loadedImage(customer.locator('.product-modal-art img.product-photo'));
    await customer.locator('[data-action="add-cart"]').click();await customer.waitForFunction(()=>DZ.cartCount()===1);
    await loadedImage(customer.locator('#cartBody img.product-photo'));
    assert.equal(new URL(await customer.locator('#cartBody img.product-photo').getAttribute('src'),base).pathname,jpegUrl);
    await customer.screenshot({path:path.join(output,'actual-upload-customer-cart.png')});
    pass('replacement JPEG loads in public catalog, product details and cart on a separate session');

    const phone='0998'+String(Date.now()).slice(-7);
    const restricted=await owner.evaluate(async data=>{
      const role=await DZ.saveRole({name:'photoqa_viewer_'+data.suffix,permissions:['products.view']});
      const user=await DZ.createUser({name:'مشاهده‌گر آزمون عکس',phone:data.phone,roleId:role.id});
      return {role,user};
    },{suffix,phone});restrictedUserId=restricted.user.id;
    const restrictedContext=await browser.newContext({viewport:{width:1024,height:768},reducedMotion:'reduce'});contexts.push(restrictedContext);
    const viewer=await restrictedContext.newPage();await loaded(viewer);await login(viewer,phone,outboxPath,true);
    await viewer.locator('[data-admin-action="tab"][data-tab="products"]').click();
    assert.equal(await viewer.locator('[data-admin-action="product-new"],[data-admin-action="product-edit"],#adminProductImage').count(),0);
    const denied=await viewer.evaluate(async data=>{
      const bootstrap=await (await fetch('api.php?action=bootstrap')).json();
      const file=new File([Uint8Array.from(atob(data.encoded),c=>c.charCodeAt(0))],'forbidden.png',{type:'image/png'});
      const body=new FormData();body.append('image',file);
      const upload=await fetch('api.php?action=product.imageUpload',{method:'POST',headers:{'X-CSRF-Token':bootstrap.csrf},body});
      const result=await upload.json();
      const update=await fetch('api.php?action=product.save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':result.csrf},body:JSON.stringify({product:{id:data.id,imageUrl:null}})});
      return {upload:upload.status,update:update.status};
    },{encoded:images.png.toString('base64'),id:productId});
    assert.deepEqual(denied,{upload:403,update:403});pass('view-only role cannot upload or alter a product photo through UI or direct API');

    await owner.locator(`[data-admin-action="product-edit"][data-id="${productId}"]`).click();
    await owner.locator('[data-admin-action="product-image-remove"]').click();await saveForm(owner);
    product=await owner.evaluate(id=>DZ.getProduct(id),productId);
    assert.equal(product.imageUrl,null);assert.equal(product.variants[0].id,variantId);assert.equal(product.variants[0].stock,9);
    await loaded(customer);await customer.locator('[data-action="cart"]').click();
    assert.equal(await customer.locator('#cartBody img.product-photo').count(),0);
    assert.match(await customer.locator('#cartBody').textContent(),/عکس|تصویر/);
    pass('removing product photo refreshes the public cart without changing inventory');
  } finally {
    if(productId)await owner.evaluate(id=>DZ.setProductEnabled(id,false),productId);
    if(restrictedUserId)await owner.evaluate(id=>DZ.setUserEnabled(id,false),restrictedUserId);
    for(const context of contexts)await context.close();
  }
}

(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true});
  try {
    const images=await photoFixtures(browser);await synthetic(browser,images);
    if(process.env.DEZHAWO_QA_PHOTOS_LIVE==='1')await live(browser,images);
    assert.deepEqual(errors,[]);pass('no uncaught JavaScript errors');
  } finally {await browser.close();}
})().catch(error=>{console.error(error.stack||error.message);process.exitCode=1;});
