(function(){
  'use strict';
  const D=()=>window.DZ, U=()=>window.UI;
  const e=value=>U().esc(String(value??''));
  const n=value=>U().num(Number(value)||0);
  const icon=name=>U().icon(name);
  const legacyNames={case:'قاب',glass:'گلس',cable:'کابل',charger:'شارژر',audio:'هندزفری',digital:'اشتراک دیجیتال'};
  const all=()=>Array.isArray(D().state.categories)?D().state.categories:Object.entries(legacyNames).map(([id,name],sortOrder)=>({id,name,parentId:null,productType:id==='digital'?'digital':'physical',enabled:true,sortOrder,legacyKey:id}));
  const get=id=>all().find(category=>category.id===id);
  const can=permission=>D().can(permission);
  const typeLabel=type=>type==='digital'?'محصول دیجیتال':'کالای فیزیکی';
  const compare=(a,b)=>Number(a.sortOrder||0)-Number(b.sortOrder||0)||String(a.name).localeCompare(String(b.name),'fa')||String(a.id).localeCompare(String(b.id));
  const children=id=>all().filter(category=>(category.parentId||null)===(id||null)).sort(compare);
  function ensureEdit(){if(!can('categories.edit'))throw new Error('برای مدیریت دسته‌بندی‌ها دسترسی ندارید.');}
  function descendants(id){
    const found=new Set(),pending=[id];
    while(pending.length){const parent=pending.pop();for(const category of children(parent)){if(category.id===id||found.has(category.id))continue;found.add(category.id);pending.push(category.id);}}
    return found;
  }
  function categoryLabel(id){
    const labels=[],seen=new Set();let category=get(id);
    while(category&&!seen.has(category.id)){seen.add(category.id);labels.unshift(String(category.name));category=get(category.parentId);}
    return labels.join(' / ');
  }
  function isActive(id){
    const seen=new Set();let category=get(id);
    if(!category)return false;
    while(category){if(seen.has(category.id)||category.enabled===false)return false;seen.add(category.id);category=get(category.parentId);}
    return true;
  }
  function ordered(){
    const values=[],seen=new Set();
    function walk(category,depth){if(seen.has(category.id))return;seen.add(category.id);values.push({category,depth});children(category.id).forEach(child=>walk(child,depth+1));}
    children(null).forEach(category=>walk(category,0));
    all().slice().sort(compare).forEach(category=>{if(!seen.has(category.id))walk(category,0);});
    return values;
  }
  function selectOptions(selectedId='',options={}){
    return ordered().filter(({category})=>options.includeDisabled||isActive(category.id)||category.id===selectedId).map(({category})=>{
      const active=isActive(category.id);
      return `<option value="${e(category.id)}" ${category.id===selectedId?'selected':''} ${!active&&!options.includeDisabled?'disabled':''}>${e(categoryLabel(category.id))}${active?'':' (غیرفعال)'}</option>`;
    }).join('');
  }
  function actionButton(name,label,id='',className='admin-link',glyph=''){
    return `<button type="button" class="${className}" data-admin-action="${name}"${name==='close'?' aria-label="بستن"':''}${id?` data-id="${e(id)}"`:''}>${glyph?icon(glyph):''}${label}</button>`;
  }
  function productCount(id){
    const legacyKey=get(id)?.legacyKey;
    return (D().state.products||[]).filter(product=>product.categoryId===id||(!product.categoryId&&(product.category===id||(legacyKey&&product.category===legacyKey)))).length;
  }
  function render(){
    if(!can('categories.view')&&!can('categories.edit'))return `<div class="empty-state">${icon('shield')}<p>دسترسی مشاهدهٔ دسته‌بندی‌ها برای نقش شما فعال نیست.</p></div>`;
    const roots=children(null),seen=new Set();
    function node(category){
      if(seen.has(category.id))return '';seen.add(category.id);
      const branch=children(category.id),count=productCount(category.id),active=isActive(category.id);
      return `<li class="admin-category-node" data-category-id="${e(category.id)}"><div class="admin-category-row"><div class="admin-category-title">${icon(branch.length?'grid':'box')}<div><strong>${e(category.name)}</strong><small>${n(count)} محصول · ${n(branch.length)} زیردسته${category.parentId?'':` · ${typeLabel(category.productType)}`}</small></div></div><div class="admin-category-controls"><span class="pill ${active?'pill-green':'pill-gray'}">${category.enabled===false?'غیرفعال':active?'فعال':'والد غیرفعال'}</span>${can('categories.edit')?`<div class="admin-category-actions">${actionButton('category-child','زیردسته',category.id,'admin-link','plus')}${actionButton('category-edit','ویرایش',category.id,'admin-link','edit')}${actionButton('category-delete','حذف',category.id,'admin-link admin-danger','trash')}</div>`:''}</div></div>${branch.length?`<ul class="admin-category-children">${branch.map(node).join('')}</ul>`:''}</li>`;
    }
    let tree=roots.map(node).join('');
    tree+=all().slice().sort(compare).filter(category=>!seen.has(category.id)).map(node).join('');
    const migrationNotice=D().state.capabilities?.categoriesReady===false?`<div class="admin-demo-banner">${icon('shield')}<span>برای افزودن و ویرایش دسته‌بندی‌ها، ابتدا کوئری ارتقای دسته‌بندی را در phpMyAdmin اجرا کنید. دسته‌های قبلی تا زمان ارتقا قابل استفاده‌اند.</span></div>`:'';
    return `${migrationNotice}<div class="admin-toolbar"><p>دسته‌های اصلی و زیردسته‌ها را بسازید؛ مثلاً قاب ← سامسونگ یا آیفون.</p>${can('categories.edit')?actionButton('category-new','افزودن دسته‌بندی','','btn btn-dark','plus'):''}</div><section class="admin-card"><div class="admin-card-heading"><h3>شاخه‌بندی محصولات</h3><span class="pill">${n(all().length)} دسته</span></div><p class="admin-form-note">دستهٔ مادر، محصولات زیردسته‌هایش را هم در فروشگاه نمایش می‌دهد. دسته‌بندی غیرفعال و شاخه‌های زیر آن در فروشگاه نمایش داده نمی‌شوند.</p>${tree?`<ul class="admin-category-tree" aria-label="درخت دسته‌بندی‌های فروشگاه">${tree}</ul>`:`<div class="empty-state">${icon('grid')}<p>هنوز دسته‌بندی‌ای نساخته‌اید.</p></div>`}</section>`;
  }
  function parentOptions(category,parentId){
    const forbidden=category?descendants(category.id):new Set();if(category)forbidden.add(category.id);
    return `<option value="" ${!parentId?'selected':''}>بدون والد؛ دستهٔ اصلی</option>`+ordered().filter(({category:choice})=>!forbidden.has(choice.id)&&(!category||choice.productType===category.productType)).map(({category:choice})=>`<option value="${e(choice.id)}" ${choice.id===parentId?'selected':''}>${e(categoryLabel(choice.id))}${isActive(choice.id)?'':' (غیرفعال)'}</option>`).join('');
  }
  function form(id='',parentId=''){
    ensureEdit();const category=id?get(id):null;if(id&&!category)throw new Error('دسته‌بندی پیدا نشد.');
    const parent=category?get(category.parentId):get(parentId),chosenParent=parent?.id||'',type=parent?.productType||category?.productType||'physical';
    const title=category?'ویرایش دسته‌بندی':parent?'افزودن زیردسته':'دسته‌بندی جدید';
    U().openModal(`<div class="modal-title"><div><small>دسته‌بندی محصولات</small><h2>${title}</h2></div>${actionButton('close',icon('close'),'','icon-btn')}</div><form data-admin-form="category" ${category?`data-id="${e(category.id)}"`:''} data-root-product-type="${e(type)}"><div class="form-grid"><label class="field"><span>نام دسته‌بندی</span><input class="input" name="name" value="${e(category?.name||'')}" required maxlength="100" placeholder="مثلاً سامسونگ" autocomplete="off"></label><label class="field"><span>دستهٔ مادر</span><select class="select" name="parentId" data-category-parent>${parentOptions(category,chosenParent)}</select></label><label class="field"><span>نوع محصول</span><select class="select" name="productType" data-category-type ${chosenParent?'disabled':''}><option value="physical" ${type==='physical'?'selected':''}>کالای فیزیکی</option><option value="digital" ${type==='digital'?'selected':''}>محصول دیجیتال / اشتراک</option></select></label><label class="field"><span>ترتیب نمایش (اختیاری)</span><input class="input" name="sortOrder" inputmode="numeric" pattern="[0-9۰-۹٠-٩]*" value="${category&&Number(category.sortOrder)!==0?e(category.sortOrder):''}" placeholder="پیش‌فرض" maxlength="7"></label><label class="admin-check"><input type="checkbox" name="enabled" ${category?.enabled===false?'':'checked'}><span>نمایش در فروشگاه</span></label><p class="admin-span-2 admin-form-note" data-category-type-note>${chosenParent?'نوع محصول از دستهٔ مادر گرفته می‌شود.':'برای لوازم جانبی، کالای فیزیکی را انتخاب کنید؛ برای اکانت و اشتراک، محصول دیجیتال.'}</p><p class="admin-span-2 admin-form-note">با غیرفعال‌کردن دسته، شاخه‌های زیر آن هم از فروشگاه پنهان می‌شوند. حذف دسته فقط وقتی ممکن است که محصول و زیردسته‌ای نداشته باشد.</p></div><p class="form-error" data-admin-error role="alert" aria-live="polite"></p><div class="form-actions">${actionButton('close','انصراف')}<button class="btn btn-dark" type="submit">${category?'ذخیره تغییرات':'افزودن دسته‌بندی'}${icon('check')}</button></div></form>`,'admin-modal');
  }
  function deleteForm(id){
    ensureEdit();const category=get(id);if(!category)throw new Error('دسته‌بندی پیدا نشد.');
    const subcategories=children(id).length,products=productCount(id),blocked=subcategories>0||products>0;
    const message=subcategories?'ابتدا زیردسته‌ها را به دستهٔ دیگری منتقل یا حذف کنید.':products?'ابتدا دسته‌بندی محصولات این دسته را تغییر دهید.':'دسته‌بندی بدون محصول و زیردسته حذف خواهد شد.';
    U().openModal(`<div class="modal-title"><div><small>مدیریت دسته‌بندی‌ها</small><h2>حذف دسته‌بندی</h2></div>${actionButton('close',icon('close'),'','icon-btn')}</div><form data-admin-form="category-delete" data-id="${e(id)}"><p class="admin-category-delete-path">${e(categoryLabel(id))}</p><p class="admin-form-note">${e(message)}</p><p class="admin-form-note">${n(products)} محصول · ${n(subcategories)} زیردسته</p><p class="form-error" data-admin-error role="alert" aria-live="polite"></p><div class="form-actions">${actionButton('close','انصراف')}<button class="btn btn-dark" type="submit" ${blocked?'disabled':''}>حذف دسته‌بندی${icon('trash')}</button></div></form>`,'admin-modal');
  }
  async function action(name,target){
    if(name==='category-new'){form();return;}
    if(name==='category-edit'){form(target.dataset.id);return;}
    if(name==='category-child'){if(!get(target.dataset.id))throw new Error('دسته‌بندی مادر پیدا نشد.');form('',target.dataset.id);return;}
    if(name==='category-delete'){deleteForm(target.dataset.id);return;}
    throw new Error('عملیات دسته‌بندی شناخته نشد.');
  }
  async function submit(formEl){
    ensureEdit();
    if(formEl.dataset.adminForm==='category-delete'){
      await D().deleteCategory(formEl.dataset.id);return {message:'دسته‌بندی حذف شد.'};
    }
    const fd=new FormData(formEl),name=String(fd.get('name')||'').trim(),parentId=String(fd.get('parentId')||'')||null;
    const parent=parentId?get(parentId):null;
    if(!name)throw new Error('نام دسته‌بندی را وارد کنید.');
    if(parentId&&!parent)throw new Error('دسته‌بندی مادر پیدا نشد؛ صفحه را تازه کنید.');
    const rawOrder=String(fd.get('sortOrder')||'').replace(/[۰-۹]/g,digit=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)).replace(/[٠-٩]/g,digit=>'٠١٢٣٤٥٦٧٨٩'.indexOf(digit));
    if(rawOrder&&!/^\d+$/.test(rawOrder))throw new Error('ترتیب نمایش باید عدد صحیح و صفر یا بیشتر باشد.');
    const sortOrder=rawOrder?Number(rawOrder):0;if(!Number.isSafeInteger(sortOrder)||sortOrder<0||sortOrder>1000000)throw new Error('ترتیب نمایش باید بین صفر و ۱۰۰۰۰۰۰ باشد.');
    const category={...(formEl.dataset.id?{id:formEl.dataset.id}:{}),name,parentId,productType:parent?.productType||String(fd.get('productType')||'physical'),enabled:fd.has('enabled'),sortOrder};
    await D().saveCategory(category);return {message:formEl.dataset.id?'دسته‌بندی ذخیره شد.':'دسته‌بندی جدید اضافه شد.'};
  }
  document.addEventListener('change',event=>{
    const target=event.target,formEl=target.closest('form[data-admin-form="category"]');if(!formEl)return;
    const type=formEl.querySelector('[data-category-type]');
    if(target.matches('[data-category-type]')){formEl.dataset.rootProductType=type.value;return;}
    if(!target.matches('[data-category-parent]'))return;
    const parent=get(target.value),note=formEl.querySelector('[data-category-type-note]');
    type.disabled=Boolean(parent);type.value=parent?.productType||formEl.dataset.rootProductType||'physical';
    note.textContent=parent?'نوع محصول از دستهٔ مادر گرفته می‌شود.':'برای لوازم جانبی، کالای فیزیکی را انتخاب کنید؛ برای اکانت و اشتراک، محصول دیجیتال.';
  });
  window.CategoryAdmin={render,form,action,submit,selectOptions,categoryLabel};
})();
