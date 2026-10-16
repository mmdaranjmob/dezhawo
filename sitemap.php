<?php
declare(strict_types=1);
require_once __DIR__ . '/app/Seo.php';
header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $base = seo_base_url();
    $products = seo_public_products();
    $escapeXml = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '<url><loc>' . $escapeXml($base . '/') . '</loc></url>' . "\n";
    foreach ($products as $product) {
        echo '<url><loc>' . $escapeXml(seo_product_url((string)$product['id'])) . '</loc>';
        $date = iso_time($product['updated_at']);
        if ($date) echo '<lastmod>' . $escapeXml($date) . '</lastmod>';
        echo '</url>' . "\n";
    }
    echo '</urlset>';
} catch (Throwable) {
    http_response_code(503);
    header('Retry-After: 300');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Store temporarily unavailable</error>';
}
