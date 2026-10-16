<?php
declare(strict_types=1);
require_once __DIR__ . '/app/Seo.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $base = seo_base_url();
    $path = rtrim((string)(parse_url($base, PHP_URL_PATH) ?? ''), '/');
    echo "User-agent: *\nAllow: " . $path . "/\n";
    foreach (['app/', 'database/', 'tests/', 'tools/', 'storage/', 'api.php', 'install.php', 'payment-callback.php', 'digipay-callback.php'] as $private) {
        echo 'Disallow: ' . $path . '/' . $private . "\n";
    }
    echo 'Sitemap: ' . $base . "/sitemap.xml\n";
} catch (Throwable) {
    http_response_code(503);
    header('Retry-After: 300');
    echo "User-agent: *\nDisallow: /\n";
}
