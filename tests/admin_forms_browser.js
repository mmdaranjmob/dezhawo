/* Real admin forms/category storefront QA on a disposable loopback *_qa DB.
 * Credentials and OTPs stay in the private QA runtime. All fixtures are removed.
 */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {spawnSync}=require('node:child_process');

const base=(process.env.DEZHAWO_QA_URL||'http://127.0.0.1:8080').replace(/\/$/,'');
const privatePath=process.env.DEZHAWO_QA_SECRETS,outboxPath=process.env.DEZHAWO_QA_OUTBOX;
assert.ok(privatePath&&outboxPath,'Provide private disposable QA fixture paths.');
assert.ok(['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname),'Forms QA requires loopback.');
const fixture=JSON.parse(fs.readFileSync(privatePath,'utf8'));
assert.ok(fixture.db_name?.endsWith('_qa'),'Forms QA requires a disposable *_qa database.');
const output=process.env.DEZHAWO_FORMS_QA_OUTPUT||'/tmp/dezhawo-admin-forms-browser';
fs.mkdirSync(output,{recursive:true});
const prefix='formqa_'+Date.now().toString(36),categories=[],products=[],errors=[];
const pass=label=>console.log('PASS '+label);
function sql(statement){
  const result=spawnSync('docker',['exec','-i',process.env.DEZHAWO_QA_DB_CONTAINER||'dezhawo-qa-db','sh','-c',
    'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u "$MARIADB_USER" --database="$MARIADB_DATABASE" -N -B'],
    {input:statement,encoding:'utf8'});
  assert.equal(result.status,0,'Disposable QA query failed; private details withheld.');
  return result.stdout.trim();
}
assert.equal(sql('SELECT DATABASE();'),fixture.db_name);
sql('DELETE FROM rate_limits;');
function otp(phone){
  const entries=fs.readdirSync(outboxPath).filter(name=>name.endsWith('.json')).map(name=>{
    const file=path.join(outboxPath,name);
    try{return {...JSON.parse(fs.readFileSync(file,'utf8')),time:fs.statSync(file).mtimeMs};}
    catch(error){if(error.code==='EACCES')return null;throw error;}
  }).filter(entry=>entry&&entry.phone===phone).sort((a,b)=>b.time-a.time);
  assert.ok(entries.length,'A private QA OTP challenge should be present.');
  return entries[0].code;
}
async function loaded(page,url='/index.php'){
  await page.goto(base+url,{waitUntil:'networkidle'});
  await page.waitForFunction(()=>DZ.state.configured===true);
}
async function login(page){
  await page.locator('.profile-btn[data-action="account"]').click();
  let form=page.locator('#loginPhoneForm');await form.locator('[name="phone"]').fill(fixture.owner_phone);
  await form.locator('[type="submit"]').click();await page.locator('#loginCodeForm').waitFor();
  form=page.locator('#loginCodeForm');await form.locator('[name="code"]').fill(otp(fixture.owner_phone));
  await form.locator('[type="submit"]').click();
  await page.locator('#modalContent [data-action="admin"]').click();
  await page.locator('#adminView').waitFor({state:'visible'});
}
async function closed(page){await page.waitForFunction(()=>!document.getElementById('modalDialog').open);}
async function categoryForm(page){return page.locator('[data-admin-form="category"]');}
async function createCategory(page,name,parentId=null){
  await page.locator('[data-admin-action="tab"][data-tab="categories"]').click();
  if(parentId)await page.locator(`[data-category-id="${parentId}"] > .admin-category-row [data-admin-action="category-child"]`).click();
  else await page.locator('[data-admin-action="category-new"]').click();
  const form=await categoryForm(page);
  assert.equal(await form.locator('[name="sortOrder"]').inputValue(),'','New sort order should be blank.');
  if(parentId){
    assert.equal(await form.locator('[name="parentId"]').inputValue(),parentId);
    assert.equal(await form.locator('[name="productType"]').isDisabled(),true);
  }
  await form.locator('[name="name"]').fill(name);
  await form.locator('[type="submit"]').click();await closed(page);
  const category=await page.evaluate(label=>DZ.state.categories.find(row=>row.name===label),name);
  assert.ok(category,'Saved category must appear after bootstrap.');categories.push(category.id);return category.id;
}
function inventory(){
  const owned=products.map(id=>"'"+id+"'").join(',');
  return sql('SELECT id,stock FROM product_variants WHERE product_id IN ('+owned+') ORDER BY id;')+'|'+
    sql('SELECT COUNT(*) FROM stock_movements WHERE product_id IN ('+owned+');');
}

(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true});
  const contexts=[],context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
  contexts.push(context);const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
  let releaseSave;
  try{
    await loaded(page);await login(page);
    await page.locator('[data-admin-action="tab"][data-tab="categories"]').click();
    await page.locator('[data-admin-action="category-new"]').click();
    let form=await categoryForm(page);await form.locator('[name="name"]').fill('   ');
    await form.locator('[type="submit"]').click();
    await form.locator('.form-error').filter({hasText:'نام دسته‌بندی'}).waitFor();
    assert.equal(await page.locator('#modalDialog').evaluate(dialog=>dialog.open),true);
    await form.locator('[data-admin-action="close"]').click();
    const rootName='قاب آزمون '+prefix,samsungName='سامسونگ '+prefix,iphoneName='آیفون '+prefix,modelName='گلکسی مدل ویژه '+prefix;
    const root=await createCategory(page,rootName),samsung=await createCategory(page,samsungName,root);
    const iphone=await createCategory(page,iphoneName,root),model=await createCategory(page,modelName,samsung);
    pass('category validation stays in the form, and saving roots/children closes each dialog');

    await page.locator(`[data-category-id="${root}"] > .admin-category-row [data-admin-action="category-edit"]`).click();
    form=await categoryForm(page);
    assert.equal(await form.locator(`[name="parentId"] option[value="${root}"]`).count(),0);
    assert.equal(await form.locator(`[name="parentId"] option[value="${model}"]`).count(),0);
    await form.locator('[name="name"]').fill(rootName+' ویرایش');
    await form.locator('[type="submit"]').click();await closed(page);
    const editedRootName=rootName+' ویرایش';
    pass('category editor excludes self/descendants as parents and persists its renamed root');

    await page.locator('[data-admin-action="tab"][data-tab="products"]').click();
    await page.locator('[data-admin-action="product-new"]').click();
    form=page.locator('[data-admin-form="product"]');
    for(const name of ['price','cost','variantStock','variantPrice'])assert.equal(await form.locator(`[name="${name}"]`).first().inputValue(),'','New '+name+' should not start with zero.');
    await form.locator('[type="submit"]').click();
    await form.locator('[data-admin-error]').filter({hasText:'نام محصول'}).waitFor();
    assert.equal(await page.locator('#modalDialog').evaluate(dialog=>dialog.open),true);
    const price=form.locator('[name="price"]');assert.equal(await price.getAttribute('type'),'text');
    await price.pressSequentially('1234567');assert.equal(await price.inputValue(),'1,234,567');
    await price.fill('۱۲۳۴۵۶۷');assert.equal(await price.inputValue(),'1,234,567');
    await price.fill('١٢٣٤٥٦٧');assert.equal(await price.inputValue(),'1,234,567');
    await context.grantPermissions(['clipboard-read','clipboard-write']);
    await page.evaluate(()=>navigator.clipboard.writeText('٢٣٤٥٦٧٨'));
    await price.focus();await price.press('ControlOrMeta+A');await price.press('ControlOrMeta+V');
    await page.waitForFunction(()=>document.querySelector('[data-admin-form="product"] [name="price"]').value==='2,345,678');
    await price.fill('1234567');await price.press('Home');await price.press('ArrowRight');await price.press('9');
    assert.equal(await price.inputValue(),'19,234,567');await price.press('Backspace');assert.equal(await price.inputValue(),'1,234,567');
    pass('prices group automatically while typing, Persian/Arabic entry, clipboard paste and middle editing');

    const productTitle='قاب فرم آزمون '+prefix;
    await form.locator('[name="title"]').fill(productTitle);
    await price.fill('12.3');await form.locator('[type="submit"]').click();
    await form.locator('[data-admin-error]').filter({hasText:'بدون اعشار'}).waitFor();
    await price.fill('1234567');
    await form.locator('[name="categoryId"]').selectOption(model);
    await form.locator('[name="cost"]').fill('0');await form.locator('[name="variantStock"]').fill('7');
    await form.locator('[name="variantPrice"]').fill('0');
    const savePattern='**/api.php?action=product.save';
    const serverError='خطای آزمایشی ذخیره؛ اطلاعات فرم را اصلاح کنید.';
    await page.route(savePattern,route=>route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({ok:false,error:serverError})}),{times:1});
    await form.locator('[type="submit"]').click();await form.locator('.form-error').filter({hasText:serverError}).waitFor();
    assert.equal(await page.locator('#modalDialog').evaluate(dialog=>dialog.open),true);
    assert.equal(await form.locator('[name="title"]').inputValue(),productTitle);
    assert.equal(await price.inputValue(),'1,234,567');assert.equal(await form.locator('[type="submit"]').isDisabled(),false);
    assert.equal(await page.evaluate(title=>DZ.state.products.some(product=>product.title===title),productTitle),false);
    pass('a server error is shown inside the open product form without losing entered values');

    let saveRequests=0,savedPayload;
    const hold=new Promise(resolve=>{releaseSave=resolve;});
    await page.route(savePattern,async route=>{saveRequests++;savedPayload=route.request().postDataJSON();await hold;await route.continue();});
    await form.evaluate(element=>{element.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));element.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
    await page.waitForFunction(()=>document.querySelector('[data-admin-form="product"]').dataset.busy==='1');
    assert.equal(await form.locator('[type="submit"]').isDisabled(),true);
    await page.waitForTimeout(100);assert.equal(saveRequests,1,'Repeated submit should send one mutation.');
    releaseSave();await closed(page);await page.unroute(savePattern);
    const primary=await page.evaluate(title=>DZ.state.products.find(product=>product.title===title),productTitle);
    assert.ok(primary);products.push(primary.id);
    assert.equal(savedPayload.product.price,1234567);assert.equal(savedPayload.product.cost,0);
    assert.equal(savedPayload.product.variants[0].price,0);assert.equal(primary.categoryId,model);
    assert.equal(primary.variants[0].stock,7);assert.equal(saveRequests,1);
    pass('a corrected product saves once, closes its dialog and sends unformatted numeric amounts');

    await page.locator(`[data-admin-action="product-edit"][data-id="${primary.id}"]`).click();
    form=page.locator('[data-admin-form="product"]');
    assert.equal(await form.locator('[name="price"]').inputValue(),'1,234,567');
    assert.equal(await form.locator('[name="cost"]').inputValue(),'0');
    assert.equal(await form.locator('[name="variantPrice"]').inputValue(),'0');
    assert.equal(await form.locator('[name="variantStock"]').inputValue(),'7');
    await form.locator('[name="variantPrice"]').fill('');
    await form.locator('[type="submit"]').click();await closed(page);
    assert.equal(await page.evaluate(id=>DZ.getProduct(id).variants[0].price??null,primary.id),null);
    const secondaryId=prefix+'_iphone';products.push(secondaryId);
    await page.evaluate(async data=>DZ.saveProduct({id:data.id,title:'قاب آیفون آزمون '+data.prefix,category:'case',categoryId:data.categoryId,
      price:543210,cost:0,enabled:true,description:'آزمون فیلتر شاخه‌ها',variants:[{id:data.id+'_v',name:'مشکی',color:'#222222',stock:4,enabled:true}]}),
      {id:secondaryId,prefix,categoryId:iphone});
    pass('editing keeps explicit zero amounts, while clearing an optional override restores the base price');
    const originalInventory=inventory();

    await page.locator('[data-admin-action="tab"][data-tab="categories"]').click();
    await page.locator(`[data-category-id="${root}"] > .admin-category-row [data-admin-action="category-delete"]`).click();
    form=page.locator('[data-admin-form="category-delete"]');
    assert.equal(await form.locator('[type="submit"]').isDisabled(),true);assert.match(await form.textContent(),/زیردسته/);
    await form.locator('[data-admin-action="close"]').click();
    const disposable=await createCategory(page,'حذف خالی '+prefix);
    await page.locator(`[data-category-id="${disposable}"] > .admin-category-row [data-admin-action="category-delete"]`).click();
    await page.locator('[data-admin-form="category-delete"] [type="submit"]').click();await closed(page);
    categories.splice(categories.indexOf(disposable),1);
    assert.equal(await page.locator(`[data-category-id="${disposable}"]`).count(),0);
    pass('category deletion explains occupied branches and closes after deleting an empty category');

    const guestContext=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});contexts.push(guestContext);
    const guest=await guestContext.newPage();guest.on('pageerror',error=>errors.push(error.message));await loaded(guest);
    await guest.locator(`#categoryGrid [data-category="${root}"]`).click();
    assert.equal(await guest.locator(`#productGrid a.product-title[href="product.php?id=${primary.id}"]`).count(),1);
    assert.equal(await guest.locator(`#productGrid a.product-title[href="product.php?id=${secondaryId}"]`).count(),1);
    await guest.locator(`#catalogFilters .category-child-filters [data-category="${samsung}"]`).click();
    assert.equal(await guest.locator(`#productGrid a.product-title[href="product.php?id=${primary.id}"]`).count(),1);
    assert.equal(await guest.locator(`#productGrid a.product-title[href="product.php?id=${secondaryId}"]`).count(),0);
    await guest.locator(`#catalogFilters .category-child-filters [data-category="${model}"]`).click();
    assert.deepEqual(await guest.locator('#catalogFilters .category-path button').allTextContents(),[editedRootName,samsungName,modelName]);
    assert.equal(await guest.locator('#catalogFilters .category-path [aria-current="page"]').getAttribute('data-category'),model);
    const categoryPath=await guest.locator('#productGrid .product-category').first().textContent();
    assert.ok(categoryPath.includes(editedRootName)&&categoryPath.includes(samsungName)&&categoryPath.includes(modelName));
    await guest.locator(`#productGrid a.product-title[href="product.php?id=${primary.id}"]`).click();
    await guest.waitForURL('**/product.php?id='+primary.id);
    const breadcrumb=await guest.locator('.product-breadcrumb').textContent();
    assert.ok(breadcrumb.includes(editedRootName)&&breadcrumb.includes(samsungName)&&breadcrumb.includes(modelName));
    pass('customers browse ancestor products, narrow to child branches and follow complete product breadcrumbs');

    for(const theme of ['light','dark'])for(const width of [390,320]){
      const device=await browser.newContext({viewport:{width,height:844},colorScheme:theme,reducedMotion:'reduce'});contexts.push(device);
      const mobile=await device.newPage();mobile.on('pageerror',error=>errors.push(error.message));
      await loaded(mobile,'/index.php?category='+model+'#catalog');await mobile.evaluate(()=>document.fonts.ready);
      const overflow=await mobile.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,nodes:[...document.querySelectorAll('body *')].filter(element=>{
        const bounds=element.getBoundingClientRect();return bounds.width>0&&(bounds.left< -1||bounds.right>innerWidth+1);
      }).slice(0,14).map(element=>({tag:element.tagName,id:element.id,class:element.className,left:Math.round(element.getBoundingClientRect().left),right:Math.round(element.getBoundingClientRect().right)}))}));
      if(overflow.scroll>overflow.width){await mobile.screenshot({path:path.join(output,'overflow-'+width+'-'+theme+'.png')});console.error('Public layout bounds',JSON.stringify(overflow));}
      assert.equal(overflow.scroll>overflow.width,false,'Public category overflow '+width+' '+theme);
      assert.deepEqual(await mobile.locator('#catalogFilters .category-path button').allTextContents(),[editedRootName,samsungName,modelName]);
      await mobile.screenshot({path:path.join(output,'categories-'+width+'-'+theme+'.png')});await device.close();contexts.pop();
    }
    await page.setViewportSize({width:320,height:844});await page.emulateMedia({colorScheme:'dark'});
    await page.locator('[data-admin-action="tab"][data-tab="categories"]').click();
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Admin category tree should fit a small phone.');
    await page.locator('[data-admin-action="category-new"]').click();
    assert.equal(await page.locator('#modalDialog').evaluate(dialog=>dialog.scrollWidth>dialog.clientWidth+1),false,'Category modal should fit a small phone.');
    await page.screenshot({path:path.join(output,'admin-category-mobile-dark.png')});
    assert.equal(inventory(),originalInventory,'Browsing, category editing and empty deletion must not modify stock.');
    assert.deepEqual(errors,[]);pass('nested categories and admin forms fit 320/390px, light/dark, without browser errors or stock changes');
  }finally{
    if(releaseSave)releaseSave();
    if(products.length)sql('DELETE FROM products WHERE id IN ('+products.map(id=>"'"+id+"'").join(',')+');');
    for(const id of categories.reverse())sql("DELETE FROM categories WHERE id='"+id+"';");
    for(const item of contexts)await item.close();await browser.close();
  }
})().catch(error=>{console.error(error.stack||error.message);process.exitCode=1;});
