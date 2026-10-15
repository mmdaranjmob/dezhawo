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
    'sms' => [
        // Required for every login, including the owner and staff.
        'provider' => 'kavenegar',
        'api_key' => '',
        'sender' => '',
    ],
    'payment' => [
        'provider' => 'zarinpal',
        'merchant_id' => '',
        'sandbox' => false,
    ],
    // These settings are ignored in production; keep them empty on the host.
    'testing' => [
        'otp_outbox' => '',
        'payment_stub' => false,
    ],
];
