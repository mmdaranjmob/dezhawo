/* Real Chromium UI checks with synthetic API replies; no database writes.
 * The normal local QA server supplies HTML/assets only. Every API request is
 * intercepted, so role changes and failures never touch a real account.
 */
const {chromium} = require('playwright');
const assert = require('node:assert/strict');

const base = (process.env.DEZHAWO_QA_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
assert.ok(['localhost','127.0.0.1','[::1]'].includes(new URL(base).hostname), 'Browser QA requires a loopback URL.');
const owner = {id:'upgrade_ui_owner',name:'مدیر آزمون',phone:'09121112233',roleId:'owner',enabled:true,
  permissions:['settings.edit','roles.edit','products.view','products.edit','categories.view','categories.edit']};
const pending = [{id:'categories-20261016',label:'دسته‌بندی و زیردسته‌ها'}];
function fixture(user=null, required=true) {
  return {configured:true,products:[],categories:[],shipping:[],currentUser:user,users:[],roles:[],cart:[],orders:[],movements:[],tickets:[],
    reservation:null,paymentMethods:[],paymentSettings:null,
    settings:{brand:'DEZHAWO',tagline:'آزمون رابط ارتقا',headerLinks:[],footerLinks:[],heroProductIds:[]},
    capabilities:{smsConfigured:true,paymentConfigured:false,temporaryLoginEnabled:false,categoriesReady:!required,databaseUpgradeRequired:required},
    ...(user?.roleId==='owner'?{databaseUpgrade:{required,pending:required?pending:[],recovery:required}}:{})};
}
let count=0;
function pass(title){count++;console.log('PASS '+title);}
function contrast(a,b){
  const luminance=value=>{
    const rgb=value.match(/[\d.]+/g).slice(0,3).map(Number).map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;});
    return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];
  };
  const values=[luminance(a),luminance(b)].sort((x,y)=>y-x);return(values[0]+.05)/(values[1]+.05);
}

(async()=>{
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true});
  const errors=[];
  try{
    const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
    const page=await context.newPage();page.on('pageerror',error=>errors.push(error.message));
    let state=fixture(),posts=0,mode='hold',release;
    let held=new Promise(resolve=>{release=resolve;});
    await page.route('**/api.php?**',async route=>{
      const request=route.request(),action=new URL(request.url()).searchParams.get('action');
      if(action==='bootstrap')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,csrf:'a'.repeat(64),data:state})});
      if(action==='database.upgrade'){
        posts++;assert.equal(request.method(),'POST');assert.deepEqual(request.postDataJSON(),{});
        assert.equal(request.headers()['x-csrf-token'],'a'.repeat(64));
        const requestMode=mode;
        if(requestMode==='hold'||requestMode==='logout')await held;
        if(requestMode==='error')return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'ارتقا انجام نشد؛ دسترسی دیتابیس را بررسی کن.',csrf:'a'.repeat(64)})});
        if(requestMode!=='logout')state=fixture(owner,false);
        return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,csrf:'a'.repeat(64),data:{message:'ارتقا کامل شد',databaseUpgrade:{required:false,pending:[],recovery:false}}})});
      }
      throw new Error('Unexpected mutation during synthetic upgrade QA: '+action);
    });
    await page.goto(base+'/index.php',{waitUntil:'networkidle'});
    await page.waitForFunction(()=>DZ.state.loading===false);
    const banner=page.locator('#databaseUpgradeBanner'),button=banner.locator('[data-database-upgrade]');
    assert.equal(await banner.isVisible(),false);
    for(const roleId of ['customer','upgrade_settings']){
      state=fixture({...owner,id:'upgrade_'+roleId,roleId,permissions:['settings.edit','roles.edit']});
      // Even an accidental server object must not authorize a non-owner UI.
      state.databaseUpgrade={required:true,pending,recovery:true};
      await page.evaluate(()=>DZ.refresh());assert.equal(await banner.isVisible(),false);
    }
    assert.equal(posts,0);pass('visitors, customers and settings staff never see or trigger the owner upgrade banner');

    state=fixture(owner);await page.evaluate(()=>DZ.refresh());await banner.waitFor({state:'visible'});
    assert.equal(await button.innerText(),'ارتقای دیتابیس');
    await button.focus();await page.keyboard.press('Enter');
    await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='busy');
    assert.equal(await button.isDisabled(),true);assert.equal(await banner.getAttribute('aria-busy'),'true');
    await button.evaluate(element=>{element.click();element.click();});
    await page.waitForTimeout(100);assert.equal(posts,1,'Repeated clicks sent duplicate upgrade requests.');
    release();await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='success');
    assert.match(await banner.textContent(),/موفقیت/);assert.equal(await button.isVisible(),false);
    await page.evaluate(()=>DZ.refresh());assert.equal(await banner.isVisible(),false);
    pass('keyboard activation sends one CSRF-protected POST, blocks duplicates and dismisses after verified success');

    state=fixture(owner);mode='error';await page.evaluate(()=>DZ.refresh());await button.click();
    await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='error');
    assert.match(await banner.locator('[data-database-upgrade-error]').innerText(),/دسترسی دیتابیس/);
    assert.equal(await button.isDisabled(),false);assert.equal(await banner.getAttribute('aria-busy'),'false');
    mode='success';await button.click();
    await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='success');
    assert.equal(posts,3);await page.evaluate(()=>DZ.refresh());assert.equal(await banner.isVisible(),false);
    pass('a failed upgrade reports inline, restores the button and retries successfully without losing the page');

    state=fixture(owner);mode='logout';held=new Promise(resolve=>{release=resolve;});await page.evaluate(()=>DZ.refresh());await button.click();
    await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='busy');
    // The refreshed bootstrap reports the revoked account while the upgrade
    // response arrives; the banner must discard success for that old owner.
    state=fixture();
    release();await page.waitForFunction(()=>DZ.state.currentUser===null);
    assert.equal(await banner.isVisible(),false);assert.equal(posts,4);
    pass('an owner session revoked during the request cannot leave a visible success or upgrade button');

    for(const theme of ['light','dark']){
      await page.setViewportSize({width:375,height:812});
      await page.evaluate(value=>document.documentElement.dataset.theme=value,theme);
      state=fixture(owner);mode='error';await page.evaluate(()=>DZ.refresh());await banner.waitFor({state:'visible'});
      let geometry=await banner.evaluate(element=>{
        const button=element.querySelector('[data-database-upgrade]'),box=element.getBoundingClientRect(),buttonBox=button.getBoundingClientRect(),style=getComputedStyle(element),buttonStyle=getComputedStyle(button);
        return{x:box.x,width:box.width,buttonHeight:buttonBox.height,buttonWidth:buttonBox.width,foreground:style.color,background:style.backgroundColor,buttonForeground:buttonStyle.color,buttonBackground:buttonStyle.backgroundColor,overflow:document.documentElement.scrollWidth>innerWidth};
      });
      assert.ok(geometry.x>=0&&geometry.width<=375&&geometry.buttonHeight>=44&&geometry.buttonWidth>=300);
      assert.equal(geometry.overflow,false,'Upgrade banner caused horizontal overflow.');
      assert.ok(contrast(geometry.foreground,geometry.background)>=4.5);
      assert.ok(contrast(geometry.buttonForeground,geometry.buttonBackground)>=4.5);
      await button.click();await page.waitForFunction(()=>document.querySelector('#databaseUpgradeBanner').dataset.state==='error');
      const colors=await banner.evaluate(element=>({foreground:getComputedStyle(element.querySelector('[data-database-upgrade-error]')).color,background:getComputedStyle(element).backgroundColor}));
      assert.ok(contrast(colors.foreground,colors.background)>=4.5,'Inline error contrast is too low in '+theme+'.');
    }
    pass('mobile light and dark banners fit the viewport with accessible button size and readable status/error contrast');
    assert.deepEqual(errors,[],'Browser reported uncaught JavaScript errors.');
    console.log(`All ${count} database upgrade browser checks passed.`);
  }finally{await browser.close();}
})().catch(error=>{console.error(error.stack||error.message);process.exitCode=1;});
