#!/usr/bin/env node
'use strict';
/* Exercise the actual same-origin API client with controlled network outcomes.
 * Pure Node: no browser, database, production config or external HTTP access. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'api.js'), 'utf8');
const mainUrl = '/uploads/products/' + 'a'.repeat(48) + '.png';
const extraUrl = '/uploads/products/' + 'b'.repeat(48) + '.png';
const acknowledgement = {id:'prd_client',imageUrl:mainUrl,galleryUrls:[extraUrl],variantIds:['var_black','var_white']};
const savedProduct = {...acknowledgement,title:'محصول آزمون',enabled:true,variants:[]};
const boot = (products = []) => ({configured:true,products,currentUser:{id:'owner_client',permissions:['products.edit']}});
const success = (data, csrf) => ({status:200,payload:{ok:true,data,csrf}});

class FormDataStub {
  constructor() { this.fields = []; }
  append(name, value) { this.fields.push([name, value]); }
}
class AbortControllerStub {
  constructor() { this.signal = {aborted:false}; }
  abort() { this.signal.aborted = true; }
}

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise,resolve,reject};
}

async function client(steps) {
  const pending = [...steps], calls = [], window = {};
  const context = vm.createContext({window,FormData:FormDataStub,AbortController:AbortControllerStub,
    setTimeout,clearTimeout,console,TypeError,
    fetch: async (url, options) => {
      const action = new URL(url, 'http://127.0.0.1/').searchParams.get('action');
      const step = pending.shift();
      calls.push({action,...options});
      assert.ok(step, 'Unexpected extra API request: ' + action);
      assert.equal(action, step.action, 'API operations arrived in the wrong order');
      assert.equal(options.credentials, 'same-origin');
      assert.equal(options.cache, 'no-store');
      assert.ok(options.signal);
      const result = typeof step.reply === 'function' ? await step.reply(calls.at(-1)) : step.reply;
      return {ok:result.status >= 200 && result.status < 300,status:result.status,
        json:async () => result.payload};
    }});
  vm.runInContext(source, context, {filename:'assets/api.js'});
  await window.DZ.ready;
  assert.equal(window.DZ.state.configured, true, 'Initial API bootstrap failed');
  return {api:window.DZ,calls,done:() => assert.equal(pending.length,0,'An expected API request was never sent')};
}

async function uploadWithoutRefresh() {
  const file = {name:'chosen.png',type:'image/png'}, result = {imageUrl:mainUrl};
  const harness = await client([
    {action:'bootstrap',reply:success(boot(), 'csrf_initial')},
    {action:'product.imageUpload',reply:call => {
      assert.equal(call.method,'POST');
      assert.equal(call.headers['X-CSRF-Token'],'csrf_initial');
      assert.equal(call.headers['Content-Type'],undefined,'Multipart boundary must belong to the browser');
      assert.ok(call.body instanceof FormDataStub);
      assert.deepEqual(call.body.fields,[['image',file]]);
      return success(result,'csrf_upload');
    }},
  ]);
  const state = harness.api.state;
  let notifications = 0;
  harness.api.subscribe(() => notifications++);
  assert.deepEqual(await harness.api.uploadProductImage(file),result);
  assert.equal(harness.calls.length,2,'Successful upload unexpectedly fetched the catalog again');
  assert.equal(harness.api.state,state,'Uploading an unattached photo replaced catalog state');
  assert.equal(notifications,0,'Uploading a photo rerendered a draft product form');
  harness.done();
}

async function acknowledgedAndVerified() {
  const input = {id:'prd_client',title:'محصول آزمون',imageUrl:mainUrl,galleryUrls:[extraUrl]};
  const harness = await client([
    {action:'bootstrap',reply:success(boot(),'csrf_initial')},
    {action:'product.save',reply:call => {
      assert.equal(call.headers['X-CSRF-Token'],'csrf_initial');
      assert.equal(call.headers['Content-Type'],'application/json');
      assert.deepEqual(JSON.parse(call.body),{product:input});
      return success(acknowledgement,'csrf_saved');
    }},
    {action:'bootstrap',reply:success(boot([savedProduct]),'csrf_fresh')},
  ]);
  assert.deepEqual(await harness.api.saveProduct(input),acknowledgement);
  assert.equal(harness.api.getProduct('prd_client').imageUrl,mainUrl);
  assert.deepEqual(harness.api.getProduct('prd_client').galleryUrls,[extraUrl]);
  assert.deepEqual(harness.calls.map(call=>call.action),['bootstrap','product.save','bootstrap']);
  harness.done();
}

async function committedDespiteRefreshFailure() {
  const harness = await client([
    {action:'bootstrap',reply:success(boot(),'csrf_initial')},
    {action:'product.save',reply:success(acknowledgement,'csrf_saved')},
    {action:'bootstrap',reply:() => { throw new TypeError('Synthetic refresh network failure'); }},
  ]);
  await assert.rejects(harness.api.saveProduct(savedProduct),error => {
    assert.match(error.message,/محصول ثبت شد/);
    assert.deepEqual(error.committedProduct,acknowledgement);
    assert.deepEqual(error.committedProduct.variantIds,['var_black','var_white']);
    return true;
  });
  assert.equal(harness.calls.filter(call=>call.action==='product.save').length,1,'Committed save was retried');
  harness.done();
}

async function mismatchedReadback(product) {
  const harness = await client([
    {action:'bootstrap',reply:success(boot(),'csrf_initial')},
    {action:'product.save',reply:success(acknowledgement,'csrf_saved')},
    {action:'bootstrap',reply:success(boot(product ? [product] : []),'csrf_fresh')},
  ]);
  await assert.rejects(harness.api.saveProduct(savedProduct),error => {
    assert.match(error.message,/محصول ثبت شد/);
    assert.deepEqual(error.committedProduct,acknowledgement);
    return true;
  });
  assert.equal(harness.calls.filter(call=>call.action==='product.save').length,1);
  harness.done();
}

async function rejectedWriteRecovers(failedRefresh = false) {
  const rejectedMessage = 'موجودی واردشده از تعداد رزرو کمتر است.';
  const refreshed = {...savedProduct,variants:[{id:'var_black',stock:7,available:2}]};
  const harness = await client([
    {action:'bootstrap',reply:success(boot(),'csrf_initial')},
    {action:'product.save',reply:{status:409,payload:{ok:false,error:rejectedMessage,csrf:'csrf_rejected'}}},
    {action:'bootstrap',reply:failedRefresh ? () => { throw new TypeError('Synthetic recovery failure'); }
      : success(boot([refreshed]),'csrf_recovered')},
  ]);
  await assert.rejects(harness.api.saveProduct(savedProduct),error => {
    assert.equal(error.message,rejectedMessage,'Recovery replaced the original rejection');
    assert.equal(error.committedProduct,undefined,'Rejected save incorrectly reported a committed product');
    return true;
  });
  if (!failedRefresh) assert.equal(harness.api.getProduct('prd_client').variants[0].stock,7);
  assert.equal(harness.calls.filter(call=>call.action==='product.save').length,1,'Rejected save was retried automatically');
  harness.done();
}

async function serializedUploadsAndCsrf() {
  const held = deferred(), started = deferred();
  const harness = await client([
    {action:'bootstrap',reply:success(boot(),'csrf_initial')},
    {action:'product.imageUpload',reply:async call => {
      assert.equal(call.headers['X-CSRF-Token'],'csrf_initial');
      started.resolve();
      await held.promise;
      return success({imageUrl:mainUrl},'csrf_first_photo');
    }},
    {action:'product.imageUpload',reply:call => {
      assert.equal(call.headers['X-CSRF-Token'],'csrf_first_photo','Second upload used stale CSRF');
      return success({imageUrl:extraUrl},'csrf_second_photo');
    }},
    {action:'product.save',reply:call => {
      assert.equal(call.headers['X-CSRF-Token'],'csrf_second_photo','Save did not use the latest upload CSRF');
      return success(acknowledgement,'csrf_saved');
    }},
    {action:'bootstrap',reply:success(boot([savedProduct]),'csrf_refreshed')},
    {action:'product.imageUpload',reply:call => {
      assert.equal(call.headers['X-CSRF-Token'],'csrf_refreshed','Post-save request ignored the bootstrap CSRF');
      return success({imageUrl:mainUrl},'csrf_final');
    }},
  ]);
  const first = harness.api.uploadProductImage({name:'main.png'});
  const second = harness.api.uploadProductImage({name:'extra.png'});
  const save = harness.api.saveProduct(savedProduct);
  await started.promise;
  assert.deepEqual(harness.calls.map(call=>call.action),['bootstrap','product.imageUpload'],
    'A queued upload or save overtook an unfinished request');
  held.resolve();
  const values = await Promise.all([first,second,save]);
  assert.deepEqual(values,[{imageUrl:mainUrl},{imageUrl:extraUrl},acknowledgement]);
  await harness.api.uploadProductImage({name:'later.png'});
  assert.deepEqual(harness.calls.map(call=>call.action),
    ['bootstrap','product.imageUpload','product.imageUpload','product.save','bootstrap','product.imageUpload']);
  harness.done();
}

(async () => {
  const checks = [
    ['successful multipart upload returns its durable URL without another bootstrap or draft rerender',uploadWithoutRefresh],
    ['acknowledged save verifies the fresh primary and gallery before reporting success',acknowledgedAndVerified],
    ['acknowledged save followed by network failure retains committed product and variant IDs',committedDespiteRefreshFailure],
    ['missing saved product in fresh state reports the acknowledged commit',() => mismatchedReadback(null)],
    ['stale primary image in fresh state reports the acknowledged commit',() => mismatchedReadback({...savedProduct,imageUrl:null})],
    ['stale gallery in fresh state reports the acknowledged commit',() => mismatchedReadback({...savedProduct,galleryUrls:[]})],
    ['rejected write recovers fresh stock while preserving the original error',() => rejectedWriteRecovers()],
    ['failed recovery preserves the original rejection and never claims a commit',() => rejectedWriteRecovers(true)],
    ['uploads and save serialize requests and carry each newly returned CSRF token forward',serializedUploadsAndCsrf],
  ];
  for (const [label,check] of checks) {
    await check();
    process.stdout.write('PASS '+label+'\n');
  }
  process.stdout.write(`All ${checks.length} product save client checks passed.\n`);
})().catch(error => { console.error(error);process.exitCode=1; });
