<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>

<html lang="fa" dir="rtl">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f5f4ef">
<script src="assets/theme.js"></script>
<meta name="description" content="دژاوو؛ فروشگاه لوازم جانبی موبایل و اشتراک‌های دیجیتال. انتخاب‌های کوچک برای روزهای بهتر.">
<title>DEZHAWO — دژاوو، جزئیات کوچک برای روزهای بهتر</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' rx='22' fill='%23ef5d44'/%3E%3Ctext x='21' y='58' font-family='Arial' font-weight='900' font-size='52' fill='%230f0f0e'%3ED%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/theme.css">
</head>
<body>
<div class="runtime-status" id="runtimeStatus" role="status">در حال دریافت اطلاعات فروشگاه…</div>
<div id="storeView">
<div class="announcement"><span data-icon="spark"></span><span id="noticeText">انتخاب‌های کوچک، برای روزهای بهتر</span><span>SMALL DETAILS. BETTER DAYS.</span></div>
<header class="hdr" id="header" data-open="false">
<div class="util"><div class="util-links"><span class="util-item"><span data-icon="truck"></span>ارسال به سراسر ایران</span><span class="util-item"><span data-icon="shield"></span>خرید با خیال راحت</span></div><div class="util-links"><button data-action="support">پیگیری سفارش و پشتیبانی</button><button data-action="account" id="accountEntryLabel">ورود / ثبت‌نام</button><button data-action="admin" data-management-entry hidden>پنل مدیریت</button></div></div>
<div class="main-nav"><a class="brand" href="#" aria-label="دژاوو، صفحهٔ اصلی"><div class="brand-name en" id="headerBrand">DEZHAWO<span>®</span></div><div class="brand-tagline" id="headerTagline">دژاوو؛ انتخابی که می‌ماند.</div></a><nav class="nav-links" id="mainLinks" aria-label="ناوبری اصلی"><a class="nav-link" href="#catalog" data-filter-link="all">لوازم جانبی<span data-icon="chevron"></span></a><a class="nav-link" href="#digital">دنیای دیجیتال<span class="pill" style="font-size:8px;padding:1px 5px;background:var(--yellow);border:0">جدید</span></a><a class="nav-link" href="#story">داستان دژاوو</a><button class="nav-link" data-action="support">پشتیبانی</button></nav><div class="nav-tools"><?php require __DIR__ . '/app/theme-control.php'; ?><button data-action="search" aria-label="جست‌وجوی محصولات"><span data-icon="search"></span></button><button class="profile-btn" data-action="account" aria-label="حساب کاربری"><span data-icon="user"></span></button><button class="cart-button" data-action="cart" aria-label="سبد خرید"><span data-icon="bag"></span><span class="cart-count" id="cartCount" hidden>۰</span></button><button class="menu-btn" id="menuBtn" data-action="menu" aria-label="باز کردن منو" aria-expanded="false" aria-controls="mainLinks"><span data-icon="menu"></span></button></div></div>
</header>
<main>
<section class="hero container" aria-labelledby="heroTitle"><div class="hero-inner"><div class="hero-copy"><div class="eyebrow">برای گوشی‌ات. برای روزمره‌ات.</div><h1 id="heroTitle"><span class="line">جزئیات کوچک،</span><span class="line">حالِ خوبِ</span><span class="line accent">هر روز.<span class="tiny-star" aria-hidden="true">✳︎</span></span></h1><p>از یک قاب خوش‌رنگ تا صدای موسیقی موردعلاقه‌ات؛<br>چیزهایی که روزت را کمی بهتر می‌کنند، اینجا پیدا کن.</p><div class="hero-actions"><a href="#catalog" class="btn btn-dark">کشف محصولات<span class="arrow-circle" data-icon="arrow"></span></a><a href="#digital" class="btn-text">اشتراک‌های دیجیتال<span data-icon="arrow"></span></a></div><div class="hero-proof"><span class="proof-mark"><span data-icon="check"></span>انتخاب با دقت</span><span class="proof-separator"></span><span>ارسال سراسری</span><span class="proof-separator"></span><span>پشتیبانی همراهت</span></div></div><div class="hero-mosaic" id="heroMosaic" aria-hidden="true"></div></div><div class="hero-bottom"><a href="#catalog" class="scroll-link"><span data-icon="arrow"></span>کمی پایین‌تر، انتخاب تو منتظر است</a><span class="en">CURATED FOR YOUR EVERYDAY / 01</span></div></section>
<section class="categories container" aria-label="دسته‌بندی محصولات"><div class="category-grid" id="categoryGrid"></div></section>
<section class="catalog container" id="catalog" aria-labelledby="catalogTitle"><div class="section-head"><div><div class="eyebrow" style="font-size:10px;margin-bottom:9px">منتخب دژاوو</div><h2 id="catalogTitle">همراه‌های روزمرهٔ تو</h2><p>خوش‌ساخت، کاربردی و کمی متفاوت.</p></div><span class="section-index">THE EVERYDAY EDIT / 02</span></div><div class="catalog-controls"><div class="filters" id="catalogFilters" aria-label="فیلتر دسته‌بندی"></div><select class="catalog-sort" id="catalogSort" aria-label="مرتب‌سازی محصولات"><option value="default">مرتب‌سازی: منتخب دژاوو</option><option value="low">ارزان‌ترین</option><option value="high">گران‌ترین</option><option value="stock">موجودها اول</option></select></div><div class="search-box" id="catalogSearchBox" hidden><span data-icon="search"></span><input id="catalogSearch" type="search" placeholder="اسم محصول یا مدل گوشی را بنویس…" aria-label="جست‌وجو در محصولات"><button data-action="clear-search" aria-label="بستن جست‌وجو"><span data-icon="close"></span></button></div><div class="product-grid" id="productGrid"></div><div class="catalog-end"><button class="btn btn-light" id="showMoreBtn" data-action="more">دیدن همهٔ انتخاب‌ها<span data-icon="arrow"></span></button></div></section>
<section class="digital container" id="digital" aria-labelledby="digitalTitle"><div class="digital-banner"><div><div class="eyebrow">یک ارتقا برای دنیای دیجیتال تو</div><h2 id="digitalTitle">بیشتر از یک اشتراک.</h2><p>با تلگرام پریمیوم، محدودیت‌ها را کمتر کن و امکانات بیشتری داشته باش. مدت اشتراکت را انتخاب کن؛ باقی‌اش با ما.</p><button class="btn btn-dark" data-action="digital">کشف تلگرام پریمیوم<span data-icon="arrow"></span></button></div><div class="digital-art" id="digitalArt" aria-hidden="true"><span class="star">✦</span><span class="floating-label">دنیای تو، با امکانات بیشتر ✨</span></div></div></section>
<section class="values container" aria-label="ویژگی‌های خرید"><div class="value-item"><span data-icon="box"></span><h3>بسته‌بندی با دقت</h3><p>هر سفارش، آمادهٔ رسیدن به دست تو.</p></div><div class="value-item"><span data-icon="truck"></span><h3>انتخاب روش ارسال</h3><p>روشی را انتخاب کن که برایت مناسب‌تر است.</p></div><div class="value-item"><span data-icon="ticket"></span><h3>همیشه راهی برای گفتگو</h3><p>سؤالی داری؟ از حساب کاربری تیکت بفرست.</p></div><div class="value-item"><span data-icon="palette"></span><h3>رنگی که مال توست</h3><p>همان محصول، با رنگ و حال‌وهوای خودت.</p></div></section>
<section class="story container" id="story" aria-labelledby="storyTitle"><div class="story-copy"><div class="eyebrow">از ویترین، تا دنیای تو</div><h2 id="storyTitle">یک فروشگاه.<br>هزار جزئیات دوست‌داشتنی.</h2><p>دژاوو از علاقه به چیزهای کوچک و کاربردی می‌آید. اینجا انتخاب‌هایی را کنار هم گذاشته‌ایم که هم به کارت بیایند، هم دوستشان داشته باشی؛ چه از مغازه خرید کنی، چه از همین‌جا.</p><button class="btn-text" data-action="about">بیشتر با دژاوو آشنا شو<span data-icon="arrow"></span></button></div><div class="story-art" aria-hidden="true"><span class="asterisk">✳︎</span><div class="en">GOOD TECH.<br>BETTER DAYS.<small>THE DEZHAWO PHILOSOPHY</small></div></div></section>
</main>
<footer class="footer container"><div class="newsletter" id="support"><div><h3>انتخاب خوب، از یک سؤال شروع می‌شود.</h3><p>برای انتخاب محصول یا پیگیری خرید، با ما در ارتباط باش.</p></div><button class="btn btn-light" data-action="support">گفتگو با دژاوو<span data-icon="arrow"></span></button></div><div class="footer-grid"><div class="footer-brand"><div class="brand-name en" id="footerBrand">DEZHAWO<span style="color:var(--red)">®</span></div><p id="footerText">جزئیات کوچک برای روزهای بهتر. فروشگاه لوازم جانبی موبایل و اشتراک‌های دیجیتال.</p></div><div><h3 id="footerStoreTitle">دنیای دژاوو</h3><div class="footer-links" id="footerStoreLinks"><a href="#catalog">لوازم جانبی موبایل</a><a href="#digital">اشتراک‌های دیجیتال</a><a href="#story">دربارهٔ دژاوو</a><button data-action="favorites">علاقه‌مندی‌های من</button></div></div><div><h3 id="footerHelpTitle">کنار تو هستیم</h3><div class="footer-links" id="footerHelpLinks"><button data-action="account">حساب کاربری و سفارش‌ها</button><button data-action="support">ثبت و پیگیری تیکت</button><button data-action="shipping-info">روش‌های ارسال</button><button data-action="policies">راهنمای خرید و مرجوعی</button></div></div><div class="footer-contact-col"><h3>یک سلام به دژاوو</h3><div class="footer-contact" id="footerContact"></div><div id="enamadSlot"></div></div></div><div class="footer-bottom"><span>© دژاوو · تمامی حقوق محفوظ است.</span><button data-action="admin" data-management-entry hidden>پنل مدیریت ↖</button><span class="en">DESIGNED FOR THE LITTLE THINGS.</span></div></footer>
</div>
<main id="adminView" hidden></main>
<dialog id="modalDialog" aria-label="پنجرهٔ فروشگاه"><button class="modal-close" data-action="close-modal" aria-label="بستن پنجره"><span data-icon="close"></span></button><div class="modal-inner" id="modalContent"></div></dialog>
<dialog id="cartDialog" class="cart-drawer" aria-label="سبد خرید"><div class="cart-head"><h2>سبد تو <span class="small muted" id="cartTitleCount"></span></h2><button data-action="close-cart" aria-label="بستن سبد خرید"><span data-icon="close"></span></button></div><div class="cart-body" id="cartBody"></div><div class="cart-bottom" id="cartBottom"></div></dialog>
<div class="toast-stack" id="toastStack" role="status" aria-live="polite"></div>



<script src="assets/api.js" defer></script>
<script src="assets/admin.js" defer></script>
<script src="assets/store.js" defer></script>
</body>
</html>
