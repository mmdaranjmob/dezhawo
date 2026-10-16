/* Same-origin PHP client. All inventory, accounts and orders are authoritative on the server. */
(() => {
  'use strict';
  const knownPermissions = ['products.view','products.edit','products.delete','inventory.view','inventory.edit','pos.create','orders.view','orders.edit','finance.view','tickets.view','tickets.reply','shipping.edit','users.edit','roles.edit','appearance.edit','enamad.edit','settings.edit'];
  const defaults = {
    configured: false, loading: true, products: [], shipping: [], currentUser: null,
    users: [], roles: [], cart: [], reservation: null, orders: [], movements: [], tickets: [], paymentMethods: [], paymentSettings: null,
    capabilities: {smsConfigured: false, paymentConfigured: false, temporaryLoginEnabled: false},
    settings: {brand:'DEZHAWO', tagline:'دژاوو؛ انتخابی که می‌ماند.', footerText:'دژاوو؛ لوازم جانبی برای هر روز، انتخابی برای ماندن.'}
  };
  let state = {...defaults};
  let csrf = '';
  let queue = Promise.resolve();
  const listeners = new Set();
  const notify = () => { for (const fn of listeners) { try { fn(); } catch (err) { console.error('Store render error', err); } } };
  function enqueue(work) {
    const next = queue.then(work, work);
    queue = next.catch(() => {});
    return next;
  }
  async function request(action, data, method = 'POST') {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 45000);
    try {
      const multipart = typeof FormData !== 'undefined' && data instanceof FormData;
      const response = await fetch('api.php?action=' + encodeURIComponent(action), {
        method, credentials:'same-origin', cache:'no-store', signal:controller.signal,
        headers: method === 'POST' ? {'X-CSRF-Token':csrf,...(multipart?{}:{'Content-Type':'application/json'})} : {'Accept':'application/json'},
        ...(method === 'POST' ? {body:multipart?data:JSON.stringify(data || {})} : {})
      });
      let payload;
      try { payload = await response.json(); } catch (_) { throw new Error('پاسخ سرور قابل خواندن نیست. تنظیمات PHP و اتصال پایگاه داده را بررسی کنید.'); }
      if (typeof payload.csrf === 'string') csrf = payload.csrf;
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'انجام عملیات ممکن نشد. دوباره تلاش کنید.');
      return payload.data;
    } catch (err) {
      if (err.name === 'AbortError') throw new Error('پاسخ سرور طول کشید. وضعیت سفارش را بررسی کنید و سپس دوباره تلاش کنید.');
      if (err instanceof TypeError) throw new Error('ارتباط با فروشگاه برقرار نشد. اتصال اینترنت را بررسی کنید.');
      throw err;
    } finally { clearTimeout(timer); }
  }
  async function bootstrap() {
    const incoming = await request('bootstrap', null, 'GET');
    state = {...defaults, ...incoming, loading:false, settings:{...defaults.settings,...(incoming.settings || {})}};
    notify();
    return state;
  }
  const ready = enqueue(bootstrap).catch(err => {
    state = {...defaults, loading:false, error:err.message}; notify(); return state;
  });
  function mutate(action, data) {
    return enqueue(async () => {
      await ready;
      if (!state.configured) throw new Error(state.error || 'فروشگاه هنوز راه‌اندازی نشده است. مدیر باید نصب و اتصال پایگاه داده را تکمیل کند.');
      try {
        const result = await request(action, data);
        await bootstrap();
        return result;
      } catch (err) {
        // Recover fresh session/CSRF and inventory after a rejected mutation, without retrying the mutation.
        try { await bootstrap(); } catch (_) {}
        throw err;
      }
    });
  }
  function getProduct(id) { return state.products.find(p => p.id === id) || null; }
  function price(product, variant) { return Number(variant?.price ?? product?.price ?? 0); }
  function stockAvailable(productId, variantId) {
    const p = getProduct(productId), v = p?.variants.find(x => x.id === variantId);
    if (!p || !v || p.enabled === false || v.enabled === false) return 0;
    return p.category === 'digital' ? 99999 : Math.max(0, Number(v.available ?? 0));
  }
  function cartLines() {
    return state.cart.map(line => {
      const product = getProduct(line.productId), variant = product?.variants.find(v => v.id === line.variantId);
      return product && variant ? {...line,product,variant,unitPrice:price(product,variant)} : null;
    }).filter(Boolean);
  }
  const DZ = {
    ready, refresh:() => enqueue(bootstrap), permissions:knownPermissions,
    products:() => state.products, getProduct, price, stockAvailable, cartLines,
    cartIssues:() => state.cart.filter(line => { const product=getProduct(line.productId),variant=product?.variants.find(v=>v.id===line.variantId);return !product||!variant||product.enabled===false||variant.enabled===false;}),
    cartCount:() => state.cart.reduce((sum,line) => sum + Number(line.quantity),0),
    cartTotal:() => cartLines().reduce((sum,line) => sum + line.quantity * line.unitPrice,0),
    can:permission => Boolean(state.currentUser?.permissions?.includes(permission)),
    addCart:(productId,variantId,quantity = 1) => mutate('cart.add',{productId,variantId,quantity}),
    setCartQty:(productId,variantId,quantity) => mutate('cart.set',{productId,variantId,quantity}),
    reserveCart:() => mutate('reservation.create',{}), releaseReservation:() => mutate('reservation.release',{}),
    checkout:options => mutate('order.checkout',options), resumeOrder:id => mutate('order.resume',{id}), sellPOS:options => mutate('pos.sale',options),
    savePaymentSettings:settings => mutate('payments.settings',{settings}),
    submitTransfer:(orderId,reference,note) => mutate('transfer.submit',{orderId,reference,note}),
    reviewTransfer:(orderId,decision,note) => mutate('transfer.review',{orderId,decision,note}),
    reportDelivery:orderId => mutate('order.deliver',{orderId}),
    adjustStock:(productId,variantId,delta,reason) => mutate('inventory.adjust',{productId,variantId,delta,reason}),
    saveProduct:product => mutate('product.save',{product}),
    uploadProductImage:file => { const data=new FormData();data.append('image',file);return mutate('product.imageUpload',data); },
    setVariantEnabled:(productId,variantId,enabled) => mutate('variant.enabled',{productId,variantId,enabled}),
    setProductEnabled:(id,enabled) => mutate('product.enabled',{id,enabled}), deleteProduct:id => mutate('product.delete',{id}),
    saveSettings:settings => mutate('settings.save',{settings}), saveShipping:shipping => mutate('shipping.save',{shipping}),
    disableShipping:id => mutate('shipping.disable',{id}), saveRole:role => mutate('role.save',{role}),
    deleteRole:(id,replacementId) => mutate('role.delete',{id,replacementId}),
    createUser:user => mutate('user.create',user),
    setUserRole:(userId,roleId) => mutate('user.role',{userId,roleId}), setUserEnabled:(userId,enabled) => mutate('user.enabled',{userId,enabled}),
    setOrderStatus:(id,status) => mutate('order.status',{id,status}), setOrderTracking:(id,trackingCode) => mutate('order.tracking',{id,trackingCode}),
    createTicket:(subject,message,orderId) => mutate('ticket.create',{subject,message,orderId}),
    replyTicket:(id,message) => mutate('ticket.reply',{id,message}), setTicketStatus:(id,status) => mutate('ticket.status',{id,status}),
    requestOtp:phone => mutate('auth.requestOtp',{phone}), verifyOtp:(phone,code) => mutate('auth.verifyOtp',{phone,code}),
    temporaryLogin:(phone,password) => mutate('auth.temporaryLogin',{phone,password}),
    logout:() => mutate('auth.logout',{}),
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); }
  };
  Object.defineProperty(DZ,'state',{get:() => state});
  window.DZ = DZ;
})();
