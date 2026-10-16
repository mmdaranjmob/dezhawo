<?php
declare(strict_types=1);

/** Public, server-rendered catalog data. Never loads accounts or merchant secrets. */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ProductImages.php';

function seo_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function seo_base_url(): string
{
    $base = rtrim((string)(config()['app']['base_url'] ?? ''), '/');
    $parts = parse_url($base);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20<>"\']/u', $base)) {
        app_error('آدرس عمومی فروشگاه تنظیم نشده است.', 503);
    }
    return $base;
}

function seo_product_url(string $id): string
{
    return seo_base_url() . '/product.php?id=' . rawurlencode($id);
}

function seo_category(string $category): string
{
    return ['case'=>'قاب گوشی', 'glass'=>'گلس و محافظ', 'cable'=>'کابل', 'charger'=>'شارژر',
        'audio'=>'هندزفری', 'digital'=>'اشتراک دیجیتال'][$category] ?? 'لوازم جانبی موبایل';
}

function seo_summary(string $text, int $length = 155): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}

function seo_number(int $value): string
{
    return strtr(number_format($value), ['0'=>'۰', '1'=>'۱', '2'=>'۲', '3'=>'۳', '4'=>'۴', '5'=>'۵', '6'=>'۶', '7'=>'۷', '8'=>'۸', '9'=>'۹']);
}

function seo_public_products(?string $id = null): array
{
    $sql = 'SELECT p.id,p.title,p.category,p.badge,p.description,p.image_url,p.price,p.updated_at FROM products p WHERE p.enabled=1'
        . ($id === null ? '' : ' AND p.id=?')
        . ' AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.enabled=1) ORDER BY p.created_at,p.id';
    $query = db()->prepare($sql);
    $query->execute($id === null ? [] : [$id]);
    $products = [];
    foreach ($query->fetchAll() as $row) {
        $row['variants'] = [];
        $row['price'] = (int)$row['price'];
        $row['image'] = null;
        $path = product_image_public_url($row['image_url']);
        if ($path !== null) {
            // The image helper includes a possible installation subdirectory already.
            $base = parse_url(seo_base_url());
            $row['image'] = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . $path;
        }
        $products[(string)$row['id']] = $row;
    }
    if (!$products) return [];
    $ids = array_keys($products);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = db()->prepare("SELECT v.id,v.product_id,v.name,v.color,v.stock,v.price,v.updated_at,COALESCE(r.quantity,0) AS reserved FROM product_variants v LEFT JOIN (SELECT ri.variant_id,SUM(ri.quantity) AS quantity FROM reservation_items ri JOIN reservations r ON r.id=ri.reservation_id WHERE r.status IN ('active','payment_pending') AND r.expires_at>UTC_TIMESTAMP() GROUP BY ri.variant_id) r ON r.variant_id=v.id WHERE v.enabled=1 AND v.product_id IN ($placeholders) ORDER BY v.created_at,v.id");
    $query->execute($ids);
    foreach ($query->fetchAll() as $variant) {
        $product = &$products[(string)$variant['product_id']];
        $variant['price'] = $variant['price'] === null ? $product['price'] : (int)$variant['price'];
        $variant['available'] = $product['category'] === 'digital' ? 99999 : max(0, (int)$variant['stock'] - (int)$variant['reserved']);
        $product['variants'][] = $variant;
        // A variant price/stock edit also changes the public product page.
        $product['updated_at'] = max($product['updated_at'], $variant['updated_at']);
        unset($product);
    }
    // An administrator may disable the last option between these two reads.
    // Do not publish a price or an indexable page without a public option.
    $products = array_filter($products, static fn(array $product): bool => $product['variants'] !== []);
    foreach ($products as &$product) {
        $product['lowPrice'] = min(array_column($product['variants'], 'price'));
        $product['available'] = (bool)array_filter($product['variants'], static fn(array $v): bool => $v['available'] > 0);
    }
    unset($product);
    return array_values($products);
}

function seo_json(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function seo_product_schema(array $product): array
{
    $url = seo_product_url((string)$product['id']);
    $schema = ['@context'=>'https://schema.org', '@type'=>'Product', '@id'=>$url . '#product',
        'name'=>$product['title'], 'url'=>$url, 'description'=>seo_summary($product['description'], 500),
        'category'=>seo_category($product['category']), 'sku'=>(string)$product['id']];
    if ($product['image']) $schema['image'] = [$product['image']];
    $schema['offers'] = array_map(static fn(array $variant): array => [
        '@type'=>'Offer', 'url'=>$url, 'sku'=>(string)$variant['id'], 'name'=>$product['title'] . ' — ' . $variant['name'],
        // Stored/displayed prices are toman. Schema.org uses the ISO currency IRR.
        'priceCurrency'=>'IRR', 'price'=>(string)($variant['price'] * 10),
        'availability'=>'https://schema.org/' . ($variant['available'] > 0 ? 'InStock' : 'OutOfStock'),
    ], $product['variants']);
    return $schema;
}

function seo_home_context(): array
{
    $context = ['productPage'=>false, 'product'=>null, 'products'=>[], 'status'=>200, 'base'=>'',
        'title'=>'DEZHAWO — دژاوو، جزئیات کوچک برای روزهای بهتر',
        'description'=>'دژاوو؛ فروشگاه لوازم جانبی موبایل، قاب، گلس، کابل، شارژر، هندزفری و اشتراک‌های دیجیتال. انتخاب رنگ و خرید آنلاین با ارسال به سراسر ایران.'];
    try {
        $context['base'] = seo_base_url();
        $context['products'] = seo_public_products();
    } catch (Throwable) {
        // Keep the original storefront usable for setup while avoiding an indexed outage.
        $context['status'] = 503;
    }
    return $context;
}

function seo_head(array $context): void
{
    $isProduct = $context['productPage'];
    $product = $context['product'];
    $url = $context['status'] === 200 && $context['base'] !== ''
        ? ($isProduct ? seo_product_url((string)$product['id']) : $context['base'] . '/') : '';
    echo '<title>' . seo_escape($context['title']) . '</title>' . "\n";
    echo '<meta name="description" content="' . seo_escape($context['description']) . '">' . "\n";
    echo '<meta name="robots" content="' . ($context['status'] === 200 ? 'index,follow,max-image-preview:large' : 'noindex,nofollow') . '">' . "\n";
    if ($url === '') return;
    echo '<link rel="canonical" href="' . seo_escape($url) . '">' . "\n";
    foreach (['og:locale'=>'fa_IR', 'og:site_name'=>'دژاوو | DEZHAWO', 'og:type'=>$isProduct ? 'product' : 'website',
        'og:title'=>$context['title'], 'og:description'=>$context['description'], 'og:url'=>$url] as $key=>$value) {
        echo '<meta property="' . $key . '" content="' . seo_escape($value) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="' . ($isProduct && $product['image'] ? 'summary_large_image' : 'summary') . '">' . "\n";
    echo '<meta name="twitter:title" content="' . seo_escape($context['title']) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . seo_escape($context['description']) . '">' . "\n";
    if ($isProduct && $product['image']) {
        echo '<meta property="og:image" content="' . seo_escape($product['image']) . '">' . "\n";
        echo '<meta property="og:image:alt" content="' . seo_escape($product['title']) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . seo_escape($product['image']) . '">' . "\n";
    }
    $schema = $isProduct ? seo_product_schema($product) : ['@context'=>'https://schema.org', '@type'=>'WebSite',
        'name'=>'دژاوو | DEZHAWO', 'url'=>$url, 'inLanguage'=>'fa-IR'];
    echo '<script type="application/ld+json">' . seo_json($schema) . '</script>' . "\n";
}

function seo_catalog_cards(array $products): void
{
    foreach ($products as $product) {
        $url = 'product.php?id=' . rawurlencode((string)$product['id']);
        echo '<article class="product-card"><div class="product-image"><a class="product-open" href="' . seo_escape($url) . '">';
        if ($product['image']) {
            echo '<img class="product-photo" src="' . seo_escape($product['image']) . '" alt="' . seo_escape($product['title']) . '" width="640" height="640" loading="lazy" decoding="async">';
        } else {
            echo '<span class="product-photo-placeholder">بدون تصویر</span>';
        }
        echo '</a></div><div class="product-details"><p class="product-category">' . seo_escape(seo_category($product['category'])) . '</p>';
        echo '<a class="product-title" href="' . seo_escape($url) . '">' . seo_escape($product['title']) . '</a>';
        echo '<div class="product-bottom"><span class="product-price">' . seo_number($product['lowPrice']) . ' <span class="currency">تومان</span></span><span class="small muted">' . ($product['available'] ? 'موجود' : 'ناموجود') . '</span></div></div></article>';
    }
}

function seo_product_detail(array $context): void
{
    $product = $context['product'];
    echo '<section class="product-page-detail container" aria-labelledby="productPageTitle"><nav class="product-breadcrumb" aria-label="مسیر صفحه"><a href="index.php">صفحهٔ اصلی</a><span aria-hidden="true">/</span><a href="index.php#catalog">محصولات</a>';
    if (!$product) {
        echo '</nav><div class="product-page-error"><h1 id="productPageTitle">' . seo_escape($context['status'] === 404 ? 'محصول پیدا نشد.' : 'فروشگاه موقتاً در دسترس نیست.') . '</h1><p>' . ($context['status'] === 404 ? 'این محصول حذف شده یا هنوز برای فروش منتشر نشده است.' : 'لطفاً کمی بعد دوباره تلاش کنید.') . '</p><a class="btn btn-dark" href="index.php">بازگشت به فروشگاه</a></div></section>';
        return;
    }
    echo '<span aria-hidden="true">/</span><span aria-current="page">' . seo_escape($product['title']) . '</span></nav><div class="product-page-grid"><div class="product-page-photo">';
    if ($product['image']) {
        echo '<img class="product-photo" src="' . seo_escape($product['image']) . '" alt="' . seo_escape($product['title']) . '" width="800" height="800" fetchpriority="high" decoding="async">';
    } else {
        echo '<span class="product-photo-placeholder">تصویر محصول ثبت نشده است.</span>';
    }
    echo '</div><div class="product-page-copy"><p class="eyebrow">' . seo_escape(seo_category($product['category'])) . '</p><h1 id="productPageTitle">' . seo_escape($product['title']) . '</h1><p class="product-page-description">' . nl2br(seo_escape($product['description'])) . '</p><div class="product-page-price">از ' . seo_number($product['lowPrice']) . ' <span class="currency">تومان</span></div>';
    echo '<p class="stock-note">' . ($product['available'] ? 'موجود و قابل سفارش' : 'در حال حاضر ناموجود') . '</p><div class="product-page-variants"><h2>' . ($product['category'] === 'digital' ? 'مدت اشتراک و قیمت' : 'رنگ‌ها و قیمت') . '</h2><ul>';
    foreach ($product['variants'] as $variant) {
        echo '<li><span>' . seo_escape($variant['name']) . '</span><strong>' . seo_number($variant['price']) . ' تومان</strong><span>' . ($variant['available'] > 0 ? 'موجود' : 'ناموجود') . '</span></li>';
    }
    echo '</ul></div><button type="button" class="btn btn-dark" data-action="product" data-id="' . seo_escape($product['id']) . '"' . (!$product['available'] ? ' disabled' : '') . '>انتخاب ' . ($product['category'] === 'digital' ? 'اشتراک' : 'رنگ') . ' و افزودن به سبد<span data-icon="bag"></span></button><noscript><p class="product-page-note">برای انتخاب گزینه و خرید، جاوااسکریپت مرورگر را فعال کنید.</p></noscript></div></div></section>';
}
