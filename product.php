<?php
declare(strict_types=1);
require_once __DIR__ . '/app/Seo.php';

$seoContext = ['productPage'=>true, 'product'=>null, 'products'=>[], 'base'=>'', 'status'=>404,
    'title'=>'محصول پیدا نشد | دژاوو DEZHAWO', 'description'=>'محصول موردنظر در فروشگاه دژاوو پیدا نشد.'];
$id = $_GET['id'] ?? null;
if (is_string($id) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $id)) {
    try {
        $seoContext['base'] = seo_base_url();
        $products = seo_public_products($id);
        if ($products) {
            $seoContext['product'] = $products[0];
            $seoContext['status'] = 200;
            $seoContext['title'] = $products[0]['title'] . ' | خرید از دژاوو DEZHAWO';
            $seoContext['description'] = seo_summary($products[0]['description']) ?: seo_summary('خرید ' . $products[0]['title'] . ' از فروشگاه دژاوو؛ مشاهدهٔ قیمت، گزینه‌های موجود و سفارش آنلاین.');
            $seoContext['products'] = array_values(array_filter(seo_public_products(), static fn(array $row): bool => $row['id'] !== $id));
        }
    } catch (Throwable) {
        $seoContext['product'] = null;
        $seoContext['products'] = [];
        $seoContext['status'] = 503;
        $seoContext['title'] = 'فروشگاه موقتاً در دسترس نیست | دژاوو';
        $seoContext['description'] = 'لطفاً کمی بعد دوباره تلاش کنید.';
    }
}
require __DIR__ . '/index.php';
