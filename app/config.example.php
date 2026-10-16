<?php
declare(strict_types=1);

// Copy this file to app/config.php. Never commit the copy or disclose its values.
// Generate two independent secrets with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
return [
    'app' => [
        'env' => 'production',
        // Include the subdirectory, when installed at https://example.com/shop.
        'base_url' => 'https://example.com',
        'key' => 'CHANGE_TO_RANDOM_64_HEX',
        'installation_key' => 'CHANGE_TO_RANDOM_64_HEX',
        'timezone' => 'Asia/Tehran',
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'CPANELPREFIX_dezhawo',
        'user' => 'CPANELPREFIX_user',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'dezhawo_session',
        'secure' => true,
    ],
    // Optional owner access while SMS is being configured. Disable after repair.
    // Store a PHP password_hash() result here; never store the plain password.
    'temporary_login' => [
        'enabled' => false,
        'phone' => '',
        'password_hash' => '',
    ],
    'sms' => [
        // Normal customer, staff and owner login uses SMS; the optional temporary
        // owner access above is independent and should be disabled after repair.
        // Supported providers: niazpardaz, kavenegar. No Composer package is needed.
        'provider' => 'niazpardaz',
        // Niazpardaz authentication: api_key or credentials. Select the mode for your account.
        'niazpardaz_auth' => 'api_key',
        // Used by Niazpardaz api_key mode, or by Kavenegar.
        'api_key' => '',
        // An authorized sender line from the selected provider; do not use a sample line.
        'sender' => '',
        // Used only by Niazpardaz credentials mode; leave empty for api_key mode.
        'username' => '',
        'password' => '',
    ],
    'payment' => [
        // Legacy/default online gateway. Customer choices are managed in the admin payment settings.
        'provider' => 'zarinpal',
        'merchant_id' => '',
        'sandbox' => false,
        // Keep every credential private on the host. Never copy them to database settings or Git.
        'digipay' => [
            'username' => '',
            'password' => '',
            'client_id' => '',
            'client_secret' => '',
            'sandbox' => false,
            // Obtain the merchant's approved basket identifiers from DigiPay; examples are not live IDs.
            'seller_id' => '',
            'supplier_id' => '',
            'category_id' => '',
            'brand' => 'DEZHAWO',
            'product_type' => 1,
        ],
    ],
    // These settings are ignored in production; keep them empty on the host.
    'testing' => [
        'otp_outbox' => '',
        'payment_stub' => false,
    ],
];
