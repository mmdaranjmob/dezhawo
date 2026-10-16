/* Real admin homepage selection, public product navigation and responsive photos.
 * Development-only: private QA fixtures, loopback URL and disposable *_qa DB.
 * No hosting/provider credentials or production installation are used.
 */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {spawnSync}=require('node:child_process');

const base=(process.env.DEZHAWO_QA_URL||'http://127.0.0.1:8080').replace(/\/$/,'');
const privatePath=process.env.DEZHAWO_QA_SECRETS,outboxPath=process.env.DEZHAWO_QA_OUTBOX;
assert.ok(privatePath&&outboxPath,'Provide the private disposable QA fixture paths.');
assert.ok(['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname),'Homepage QA requires loopback.');
const fixture=JSON.parse(fs.readFileSync(privatePath,'utf8'));
assert.ok(fixture.db_name?.endsWith('_qa'),'Homepage QA requires a disposable *_qa database.');
const output=process.env.DEZHAWO_HOMEPAGE_QA_OUTPUT||'/tmp/dezhawo-homepage-browser';
fs.mkdirSync(output,{recursive:true});
const errors=[],pass=label=>console.log('PASS '+label);
function sql(statement){
  const result=spawnSync('docker',['exec','-i',process.env.DEZHAWO_QA_DB_CONTAINER||'dezhawo-qa-db','sh','-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'],
    {input:statement,encoding:'utf8'});
  assert.equal(result.status,0,'Disposable QA query failed; private details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'),fixture.db_name);
sql('DELETE FROM rate_limits;');
const savedHero=sql("SELECT HEX(value) FROM settings WHERE name='heroProductIds';");
const prefix='heroui_'+Date.now().toString(36),productIds=[prefix+'_photo',prefix+'_empty',prefix+'_disabled'];
let viewerId;
function otp(phone){
  const entries=fs.readdirSync(outboxPath).filter(name=>name.endsWith('.json')).map(name=>{
    const file=path.join(outboxPath,name);
    try{return {...JSON.parse(fs.readFileSync(file,'utf8')),time:fs.statSync(file).mtimeMs};}
    catch(error){if(error.code==='EACCES')return null;throw error;}
  }).filter(entry=>entry&&entry.phone===phone).sort((a,b)=>b.time-a.time);
  assert.ok(entries.length,'The private QA outbox should contain a fresh challenge.');
  return entries[0].code;
}
async function loaded(page){
  await page.goto(base+'/index.php',{waitUntil:'networkidle'});
  await page.waitForFunction(()=>DZ.state.configured===true);
}
async function login(page,phone){
  await page.locator('.profile-btn[data-action="account"]').click();
  let form=page.locator('#loginPhoneForm');await form.locator('[name="phone"]').fill(phone);
  await form.locator('[type="submit"]').click();await page.locator('#loginCodeForm').waitFor();
  form=page.locator('#loginCodeForm');await form.locator('[name="code"]').fill(otp(phone));
  await form.locator('[type="submit"]').click();
  await page.locator('#modalContent [data-action="admin"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
async function picker(page){
  await page.locator('[data-admin-action="tab"][data-tab="homepage"]').click();
  return page.locator('[data-admin-form="hero-products"]');
}
async function choose(page,ids){
  const form=await picker(page);
  for(let i=0;i<9;i++)await form.locator('[name="heroProductId"]').nth(i).selectOption(ids[i]||'');
  await form.locator('[type="submit"]').click();
  await page.waitForFunction(expected=>JSON.stringify(DZ.state.settings.heroProductIds)===JSON.stringify(expected),ids);
}
function inventory(){
  return sql("SELECT id,stock FROM product_variants WHERE product_id LIKE '"+prefix+"%' ORDER BY id;")+'|'+
    sql("SELECT COUNT(*) FROM stock_movements WHERE product_id LIKE '"+prefix+"%';");
}

(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true});
  const contexts=[],ownerContext=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
  contexts.push(ownerContext);const owner=await ownerContext.newPage();owner.on('pageerror',error=>errors.push(error.message));
  try{
    await loaded(owner);await login(owner,fixture.owner_phone);
    await owner.evaluate(async ids=>{
      const canvas=document.createElement('canvas');canvas.width=240;canvas.height=320;
      const c=canvas.getContext('2d');c.fillStyle='#fff4d2';c.fillRect(0,0,240,320);
      c.fillStyle='#287cab';c.beginPath();c.roundRect(56,25,128,268,20);c.fill();
      c.fillStyle='#123348';c.fillRect(69,39,42,56);
      const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));
      const image=await DZ.uploadProductImage(new File([blob],'homepage-qa.png',{type:'image/png'}));
      for(let i=0;i<ids.length;i++)await DZ.saveProduct({id:ids[i],title:i===0?'قاب آبی منتخب ویترین':i===1?'کابل منتخب ناموجود':'محصول غیرفعال ویترین',
        category:i===1?'cable':'case',price:190000,description:'توضیح محصول برای آزمون واقعی ویترین صفحه اول.',enabled:i!==2,
        imageUrl:i===0?image.imageUrl:null,variants:[{id:ids[i]+'_v',name:'آبی',color:'#287cab',stock:i===0?7:0,enabled:true}]});
    },productIds);
    const originalInventory=inventory();
    let form=await picker(owner);
    assert.equal(await form.locator('[name="heroProductId"]').count(),9);
    assert.equal(await form.locator(`option[value="${productIds[2]}"]`).count(),0,'Disabled product should not be selectable.');
    await choose(owner,productIds.slice(0,2));
    form=await picker(owner);await form.locator('[name="heroProductId"]').nth(1).selectOption(productIds[0]);
    await form.locator('[type="submit"]').click();
    await owner.locator('#toastStack .toast.error').filter({hasText:'هر محصول را فقط یک بار'}).waitFor();
    assert.deepEqual(await owner.evaluate(()=>DZ.state.settings.heroProductIds),productIds.slice(0,2));
    await choose(owner,productIds.slice(0,2));
    await owner.screenshot({path:path.join(output,'admin-product-picker.png')});
    pass('owner selects actual uploaded products in nine slots and duplicate selections do not save');

    const restrictedPhone='0997'+String(Date.now()).slice(-7);
    const created=await owner.evaluate(async data=>{
      const role=await DZ.saveRole({name:data.prefix+'_settings',permissions:['settings.edit']});
      return DZ.createUser({name:'مدیر ویترین آزمون',phone:data.phone,roleId:role.id});
    },{prefix,phone:restrictedPhone});viewerId=created.id;
    const editorContext=await browser.newContext({viewport:{width:1024,height:768},reducedMotion:'reduce'});contexts.push(editorContext);
    const editor=await editorContext.newPage();editor.on('pageerror',error=>errors.push(error.message));
    await loaded(editor);await login(editor,restrictedPhone);form=await picker(editor);
    assert.equal(await editor.locator('[data-admin-action="tab"][data-tab="products"]').count(),0);
    assert.equal(await form.locator('[name="heroProductId"]').count(),9);
    assert.equal(await editor.evaluate(()=>DZ.can('inventory.edit')),false);
    await choose(editor,[productIds[1],productIds[0]]);
    assert.equal(inventory(),originalInventory,'Choosing homepage products must preserve stock and movement history.');
    pass('settings-only manager selects and reorders products without inventory or product-edit permissions');

    for(const [device,width,height] of [['desktop',1440,1000],['tablet',768,1024],['mobile',390,844],['small-mobile',320,720]]){
      for(const appearance of ['light','dark']){
        const context=await browser.newContext({viewport:{width,height},colorScheme:appearance,reducedMotion:'reduce'});contexts.push(context);
        const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));await loaded(page);
        await page.evaluate(()=>document.fonts.ready);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,device+' overflow');
        assert.notEqual(await page.locator('#heroMosaic').getAttribute('aria-hidden'),'true');
        const photo=page.locator(`#heroMosaic a.mosaic-product[href="product.php?id=${productIds[0]}"]`).first();
        const missing=page.locator(`#heroMosaic a.mosaic-product[href="product.php?id=${productIds[1]}"]`).first();
        assert.equal(await photo.getAttribute('data-action'),null,'Hero product should use native product-page navigation.');
        await photo.locator('img').evaluate(image=>image.decode());
        assert.ok(await photo.locator('img').evaluate(image=>image.naturalWidth>0));
        assert.equal(await missing.locator('img').count(),0);
        assert.match(await missing.textContent(),/ناموجود/);
        assert.equal(await page.locator('#heroMosaic a.mosaic-product:not([tabindex="-1"])').count(),2,'Only one keyboard target per selected product.');
        const bounds=await photo.evaluate(el=>{const p=el.getBoundingClientRect(),img=el.querySelector('img').getBoundingClientRect();return {cell:p.height,image:img.height,overflow:el.scrollHeight>el.clientHeight+2,color:getComputedStyle(el).color};});
        assert.ok(bounds.image>20&&bounds.image<bounds.cell,'Photo should fit inside the selected product tile.');
        assert.equal(bounds.overflow,false,'Product tile content should fit its height.');
        if(appearance==='dark'&&!((await photo.getAttribute('class')).split(' ').includes('dark')))assert.equal(bounds.color,'rgb(21, 21, 21)');
        await page.screenshot({path:path.join(output,device+'-'+appearance+'.png')});
        await context.close();contexts.pop();pass(device+' '+appearance+' actual hero photos, missing-photo state and accessible native links');
      }
    }
    const buyerContext=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});contexts.push(buyerContext);
    const buyer=await buyerContext.newPage();buyer.on('pageerror',error=>errors.push(error.message));await loaded(buyer);
    const photo=buyer.locator(`#heroMosaic a.mosaic-product[href="product.php?id=${productIds[0]}"]`).first();
    await photo.click();await buyer.waitForURL('**/product.php?id='+productIds[0]);
    assert.equal(await buyer.locator('#productPageTitle').textContent(),'قاب آبی منتخب ویترین');
    await buyer.locator('.product-page-photo img').evaluate(image=>image.decode());
    assert.equal(await buyer.locator('h1').count(),1,'Product page should have one main heading.');
    await buyer.locator(`.product-page-copy [data-action="product"][data-id="${productIds[0]}"]`).click();
    await buyer.locator('[data-action="add-cart"]').click();await buyer.waitForFunction(()=>DZ.cartCount()===1);
    assert.equal(inventory(),originalInventory,'Opening a product and adding a cart item must not deduct stock.');
    pass('customer clicks the homepage product, reaches its dedicated page and adds the actual option to cart');

    await choose(owner,[]);await loaded(buyer);
    assert.equal(await buyer.locator('#heroMosaic a.mosaic-product').count(),0);
    assert.equal(await buyer.locator('#heroMosaic').getAttribute('aria-hidden'),'true');
    assert.ok(await buyer.locator('#heroMosaic .mosaic-cell').count()>9);
    assert.equal(inventory(),originalInventory);pass('clearing homepage choices restores the original mosaic without inventory changes');
    assert.deepEqual(errors,[]);pass('no uncaught browser errors');
  }finally{
    if(savedHero)sql("INSERT INTO settings(name,value,updated_at) VALUES('heroProductIds',CONVERT(0x"+savedHero+" USING utf8mb4),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value=VALUES(value);");
    else sql("DELETE FROM settings WHERE name='heroProductIds';");
    if(await owner.evaluate(()=>Boolean(window.DZ?.state.currentUser?.permissions?.includes('products.edit')))){
      for(const id of productIds)if(await owner.evaluate(value=>Boolean(DZ.getProduct(value)),id))await owner.evaluate(value=>DZ.setProductEnabled(value,false),id);
      if(viewerId)await owner.evaluate(id=>DZ.setUserEnabled(id,false),viewerId);
    }
    for(const context of contexts)await context.close();await browser.close();
  }
})().catch(error=>{console.error(error.stack||error.message);process.exitCode=1;});
