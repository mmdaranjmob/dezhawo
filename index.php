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
<meta name="theme-color" content="#f5f2eb">
<script src="assets/theme.js"></script>
<meta name="description" content="دژاوو؛ انتخابی متمایز از لوازم جانبی موبایل و اشتراک‌های دیجیتال. زیبایی در جزئیات روزمره.">
<title>DEZHAWO — دژاوو، هنر انتخاب جزئیات</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 80 80'%3E%3Crect width='80' height='80' rx='18' fill='%23232624'/%3E%3Ctext x='21' y='58' font-family='Arial' font-weight='700' font-size='52' fill='%23d7c49c'%3ED%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="assets/theme.css">
<link rel="stylesheet" href="assets/luxury.css">
<link rel="stylesheet" href="assets/installment.css">
<link rel="stylesheet" href="assets/payments.css">
</head>
<body class="luxury-store">
<div class="runtime-status" id="runtimeStatus" role="status">در حال دریافت اطلاعات فروشگاه…</div>
<div id="storeView">
<div class="announcement"><span data-icon="spark"></span><span id="noticeText">انتخاب‌های کوچک، برای روزهای بهتر</span><span>THE ART OF EVERYDAY.</span></div>
<header class="hdr" id="header" data-open="false">
<div class="util"><div class="util-links"><span class="util-item"><span data-icon="truck"></span>ارسال به سراسر ایران</span><span class="util-item"><span data-icon="shield"></span>خرید با خیال راحت</span></div><div class="util-links"><button data-action="support">پیگیری سفارش و پشتیبانی</button><button data-action="account" id="accountEntryLabel">ورود / ثبت‌نام</button><button data-action="admin" data-management-entry hidden>پنل مدیریت</button></div></div>
<div class="main-nav"><a class="brand" href="#" aria-label="دژاوو، صفحهٔ اصلی"><div class="brand-name en" id="headerBrand">DEZHAWO<span>®</span></div><div class="brand-tagline" id="headerTagline">دژاوو؛ انتخابی که می‌ماند.</div></a><nav class="nav-links" id="mainLinks" aria-label="ناوبری اصلی"><a class="nav-link" href="#catalog" data-filter-link="all">کالکشن لوازم جانبی<span data-icon="chevron"></span></a><a class="nav-link" href="#digital">دنیای دیجیتال</a><a class="nav-link" href="#story">داستان دژاوو</a><button class="nav-link" data-action="support">پشتیبانی</button></nav><div class="nav-tools"><?php require __DIR__ . '/app/theme-control.php'; ?><button data-action="search" aria-label="جست‌وجوی محصولات"><span data-icon="search"></span></button><button class="profile-btn" data-action="account" aria-label="حساب کاربری"><span data-icon="user"></span></button><button class="cart-button" data-action="cart" aria-label="سبد خرید"><span data-icon="bag"></span><span class="cart-count" id="cartCount" hidden>۰</span></button><button class="menu-btn" id="menuBtn" data-action="menu" aria-label="باز کردن منو" aria-expanded="false" aria-controls="mainLinks"><span data-icon="menu"></span></button></div></div>
</header>
<main>
<section class="hero container" aria-labelledby="heroTitle">
  <div class="hero-inner">
    <div class="hero-copy">
      <div class="eyebrow"><span class="en">THE ART OF EVERYDAY</span><span>هنر انتخاب جزئیات</span></div>
      <h1 id="heroTitle"><span class="line">تمایز، در</span><span class="line">جزئیات</span><span class="line accent">پیداست.</span></h1>
      <p>زیباییِ یک انتخاب، هر روز همراه توست.<br>لوازم جانبی و تجربه‌های دیجیتال را با نگاهی دقیق‌تر انتخاب کن؛ برای سلیقه‌ای که شبیه هیچ‌کس نیست.</p>
      <div class="hero-actions"><a href="#catalog" class="btn btn-dark">کشف کالکشن<span class="arrow-circle" data-icon="arrow"></span></a><a href="#story" class="btn-text">جهان دژاوو<span data-icon="arrow"></span></a></div>
      <div class="hero-proof"><span class="proof-mark"><span data-icon="check"></span>انتخاب با دقت</span><span class="proof-separator"></span><span>ارسال به سراسر ایران</span><span class="proof-separator"></span><span>پشتیبانی همراه تو</span></div>
    </div>
    <div class="hero-mosaic" id="heroMosaic" aria-hidden="true"></div>
  </div>
  <div class="hero-bottom"><a href="#catalog" class="scroll-link"><span data-icon="arrow"></span>انتخاب‌های متمایز را کشف کن</a><span class="en">THOUGHTFULLY SELECTED. BEAUTIFULLY YOURS.</span></div>
</section>

<section class="categories container" aria-label="دسته‌بندی محصولات"><div class="category-intro"><span class="en">FIND YOUR ESSENTIALS</span><span>جزئی از دنیای تو</span></div><div class="category-grid" id="categoryGrid"></div></section>

<?php require __DIR__ . '/app/installment-banner.php'; ?>

<section class="catalog container" id="catalog" aria-labelledby="catalogTitle">
  <div class="section-head"><div><div class="eyebrow">انتخاب‌های دژاوو</div><h2 id="catalogTitle">همراه‌های روزمرهٔ تو</h2><p>زیبایی در ظاهر. دقت در انتخاب. همراهی در هر روز.</p></div><span class="section-index">THE CURATED COLLECTION / 01</span></div>
  <div class="catalog-controls"><div class="filters" id="catalogFilters" aria-label="فیلتر دسته‌بندی"></div><select class="catalog-sort" id="catalogSort" aria-label="مرتب‌سازی محصولات"><option value="default">مرتب‌سازی: منتخب دژاوو</option><option value="low">ارزان‌ترین</option><option value="high">گران‌ترین</option><option value="stock">موجودها اول</option></select></div>
  <div class="search-box" id="catalogSearchBox" hidden><span data-icon="search"></span><input id="catalogSearch" type="search" placeholder="اسم محصول یا مدل گوشی را بنویس…" aria-label="جست‌وجو در محصولات"><button data-action="clear-search" aria-label="بستن جست‌وجو"><span data-icon="close"></span></button></div>
  <div class="product-grid" id="productGrid"></div><div class="catalog-end"><button class="btn btn-light" id="showMoreBtn" data-action="more">مشاهدهٔ همهٔ انتخاب‌ها<span data-icon="arrow"></span></button></div>
</section>

<section class="story container" id="story" aria-labelledby="storyTitle">
  <div class="story-copy"><div class="eyebrow">فلسفهٔ دژاوو</div><h2 id="storyTitle">برای چیزهایی که<br>هر روز لمس می‌کنی.</h2><p>باور داریم سلیقه، در جزئیات خودش را نشان می‌دهد؛ در رنگ قابی که انتخاب می‌کنی، در صدایی که می‌شنوی و در چیزهایی که همیشه همراهت هستند.</p><p>دژاوو، امتداد ویترین فروشگاه ما در دنیای توست؛ جایی برای انتخابی شخصی‌تر، با همان دقت و همان همراهی.</p><button class="btn-text" data-action="about">داستان ما را بخوان<span data-icon="arrow"></span></button></div>
  <div class="story-art" aria-hidden="true"><div class="story-orbit"></div><div class="story-glass story-glass-back"></div><div class="story-glass story-glass-front"><span class="en">D.</span><span class="glass-signature en">THE BEAUTY<br>OF DETAILS.</span></div><span class="story-art-note en">A DIFFERENT POINT OF VIEW.</span><span class="story-coordinate en">DEZHAWO / OBJECTS OF EVERYDAY</span></div>
</section>

<section class="values-section container" aria-labelledby="valuesTitle"><div class="values-heading"><div class="eyebrow">تعهد ما، در هر انتخاب</div><h2 id="valuesTitle">تجربه‌ای به دقتِ سلیقهٔ تو.</h2></div><div class="values"><div class="value-item"><span class="value-number en">01</span><span data-icon="palette"></span><h3>انتخابی شخصی</h3><p>محصول و رنگ را با سلیقهٔ خودت هماهنگ کن؛ جزئیات، تفاوت را می‌سازند.</p></div><div class="value-item"><span class="value-number en">02</span><span data-icon="box"></span><h3>توجه به جزئیات</h3><p>از انتخاب محصول تا آماده‌سازی سفارش، هر مرحله برای ما اهمیت دارد.</p></div><div class="value-item"><span class="value-number en">03</span><span data-icon="truck"></span><h3>راهی برای رسیدن</h3><p>از میان روش‌های فعال فروشگاه، روش ارسال مناسب خودت را انتخاب کن.</p></div><div class="value-item"><span class="value-number en">04</span><span data-icon="ticket"></span><h3>همراهی نزدیک</h3><p>برای انتخاب، خرید و پیگیری سفارش، از حساب کاربری با ما در ارتباط باش.</p></div></div></section>

<section class="digital container" id="digital" aria-labelledby="digitalTitle"><div class="digital-banner"><div class="digital-copy"><div class="eyebrow">تجربه‌ای فراتر از روزمره</div><h2 id="digitalTitle">دنیای تو،<br><span>با امکانات بیشتر.</span></h2><p>تلگرام پریمیوم؛ انتخابی برای تجربه‌ای کامل‌تر در دنیای دیجیتال. مدت اشتراک را ببین و انتخاب مناسب خودت را پیدا کن.</p><button class="btn btn-dark" data-action="digital">کشف اشتراک‌های دیجیتال<span data-icon="arrow"></span></button><span class="digital-edition en">YOUR DIGITAL LIFE. REIMAGINED.</span></div><div class="digital-art" id="digitalArt" aria-hidden="true"><span class="digital-orbit"></span><span class="floating-label"><span data-icon="spark"></span>بُعد تازه‌ای از دنیای تو</span></div></div></section>

<section class="membership container" aria-labelledby="membershipTitle"><div class="membership-aura" aria-hidden="true"></div><div class="membership-copy"><div class="eyebrow en">THE DEZHAWO CIRCLE</div><h2 id="membershipTitle">به دنیای دژاوو خوش آمدی.</h2><p>انتخاب‌های محبوبت را نگه دار، سفارش‌هایت را دنبال کن<br>و گفت‌وگو با ما را از حساب شخصی‌ات ادامه بده.</p></div><div class="membership-action"><button class="btn btn-dark" data-action="account">عضویت در دنیای دژاوو<span data-icon="arrow"></span></button><span>ورود و عضویت با شماره موبایل</span></div></section>
</main>
<footer class="footer container"><div class="newsletter" id="support"><div><span class="en footer-kicker">LET’S TALK DETAILS.</span><h3>یک انتخاب خوب، با گفت‌وگو شروع می‌شود.</h3><p>برای انتخاب محصول یا پیگیری خرید، کنار تو هستیم.</p></div><button class="btn btn-light" data-action="support">گفت‌وگو با دژاوو<span data-icon="arrow"></span></button></div><div class="footer-grid"><div class="footer-brand"><div class="brand-name en" id="footerBrand">DEZHAWO<span>®</span></div><p id="footerText">جزئیات کوچک برای روزهای بهتر. فروشگاه لوازم جانبی موبایل و اشتراک‌های دیجیتال.</p></div><div><h3 id="footerStoreTitle">دنیای دژاوو</h3><div class="footer-links" id="footerStoreLinks"><a href="#catalog">لوازم جانبی موبایل</a><a href="#digital">اشتراک‌های دیجیتال</a><a href="#story">دربارهٔ دژاوو</a><button data-action="favorites">علاقه‌مندی‌های من</button></div></div><div><h3 id="footerHelpTitle">کنار تو هستیم</h3><div class="footer-links" id="footerHelpLinks"><button data-action="account">حساب کاربری و سفارش‌ها</button><button data-action="support">ثبت و پیگیری تیکت</button><button data-action="shipping-info">روش‌های ارسال</button><button data-action="policies">راهنمای خرید و مرجوعی</button></div></div><div class="footer-contact-col"><h3>ارتباط با دژاوو</h3><div class="footer-contact" id="footerContact"></div><div id="enamadSlot"></div></div></div><div class="footer-bottom"><span>© دژاوو · تمامی حقوق محفوظ است.</span><button data-action="admin" data-management-entry hidden>پنل مدیریت ↖</button><span class="en">LESS ORDINARY. MORE YOU.</span></div></footer>
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
