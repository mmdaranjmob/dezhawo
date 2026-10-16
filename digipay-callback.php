<?php
declare(strict_types=1);

// DigiPay returns a cross-site POST. Server verification uses the persisted attempt/state,
// independently of browser cookies and customer authentication.
define('DEZHAWO_DIGIPAY_CALLBACK', true);
require __DIR__ . '/payment-callback.php';
