<?php
declare(strict_types=1);

/** DigiPay UPG. Credentials and access tokens never enter order metadata or browser data. */
function digipay_require_credentials(bool $basket = true): void
{
    $values = config()['payment']['digipay'] ?? [];
    foreach (['username', 'password', 'client_id', 'client_secret'] as $key) {
        $value = $values[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > 2000 || preg_match('/[\r\n\x00]/', $value)) {
            app_error('اطلاعات اتصال دیجی‌پی در تنظیمات خصوصی هاست کامل نیست.', 503);
        }
    }
    if (str_contains($values['client_id'], ':')) {
        app_error('شناسهٔ اتصال دیجی‌پی معتبر نیست.', 503);
    }
    if ($basket) {
        foreach (['seller_id', 'supplier_id', 'category_id', 'brand'] as $key) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen($value) > 160 || preg_match('/[\r\n\x00]/', $value)) {
                app_error('شناسه‌های قراردادی فروشنده، تأمین‌کننده و دسته‌بندی دیجی‌پی باید در تنظیمات خصوصی تکمیل شوند.', 503);
            }
        }
        if (!in_array($values['product_type'] ?? null, [1, 2, 3, 4], true)) {
            app_error('نوع کالا در تنظیمات دیجی‌پی معتبر نیست.', 503);
        }
    }
}

function digipay_base_url(): string
{
    return (config()['payment']['digipay']['sandbox'] ?? false) === true
        ? 'https://uat.mydigipay.info/digipay/api'
        : 'https://api.mydigipay.com/digipay/api';
}

/** Fixed endpoints, TLS verification, bounded body, and no redirects. */
function digipay_http(string $path, array|string $payload, array $headers): array
{
    if (!preg_match('~^/(?:oauth/token|tickets/business\?type=11|purchases/(?:verify|deliver)\?type=(?:0|5|11|13|24))$~D', $path)) {
        throw new LogicException('Invalid DigiPay endpoint');
    }
    $response = '';
    $handle = curl_init(digipay_base_url() . $path);
    if ($handle === false) {
        app_error('آماده‌سازی ارتباط امن دیجی‌پی انجام نشد.', 503);
    }
    $configured = curl_setopt_array($handle, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 1048576) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    if (!$configured) {
        curl_close($handle);
        app_error('تنظیم ارتباط امن دیجی‌پی انجام نشد.', 503);
    }
    $ok = curl_exec($handle);
    $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failed = curl_errno($handle) !== 0;
    curl_close($handle);
    if ($ok === false || $failed || $http < 100) {
        app_error('پاسخ قطعی دیجی‌پی دریافت نشد؛ پیش از پرداخت مجدد وضعیت سفارش را با پشتیبانی بررسی کنید.', 502);
    }
    try {
        $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        app_error('پاسخ دیجی‌پی قابل بررسی نیست؛ سفارش هنوز پرداخت‌شده محسوب نمی‌شود.', 502);
    }
    if (!is_array($data)) {
        app_error('پاسخ دیجی‌پی معتبر نیست.', 502);
    }
    return ['http' => $http, 'data' => $data];
}

function digipay_access_token(bool $renew = false): string
{
    static $token = null;
    if (!$renew && is_string($token)) {
        return $token;
    }
    digipay_require_credentials(false);
    $values = config()['payment']['digipay'];
    $response = digipay_http('/oauth/token', [
        'username' => $values['username'], 'password' => $values['password'], 'grant_type' => 'password',
    ], ['Authorization: Basic ' . base64_encode($values['client_id'] . ':' . $values['client_secret']), 'Accept: application/json']);
    $access = $response['data']['access_token'] ?? null;
    if ($response['http'] !== 200 || !is_string($access) || $access === '' || strlen($access) > 16384 || preg_match('/[\r\n\x00]/', $access)) {
        app_error('احراز هویت درگاه دیجی‌پی انجام نشد؛ مدیر تنظیمات اتصال را بررسی کند.', 502);
    }
    return $token = $access;
}

function digipay_call(string $operation, int $type, array $payload): array
{
    if (!in_array($operation, ['tickets/business', 'purchases/verify', 'purchases/deliver'], true) || !in_array($type, [0, 5, 11, 13, 24], true)) {
        throw new LogicException('Invalid DigiPay operation');
    }
    payment_require_configured('digipay', $operation === 'tickets/business');
    for ($try = 0; $try < 2; $try++) {
        $response = digipay_http('/' . $operation . '?type=' . $type, json_value($payload), [
            'Authorization: Bearer ' . digipay_access_token($try === 1), 'Accept: application/json',
            'Content-Type: application/json; charset=UTF-8', 'Agent: WEB', 'Digipay-Version: 2022-02-02',
        ]);
        // A definite 401 can be retried after authentication; a timeout is never retried here.
        if ($response['http'] !== 401 || $try === 1) {
            return $response;
        }
    }
    throw new LogicException('Unreachable DigiPay response');
}

function digipay_success(array $response): bool
{
    $status = $response['data']['result']['status'] ?? null;
    return $response['http'] === 200 && ($status === 0 || $status === '0');
}

function digipay_redirect_valid(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    $sandbox = (config()['payment']['digipay']['sandbox'] ?? false) === true;
    $hosts = $sandbox ? ['uatweb.mydigipay.info'] : ['web.mydigipay.com', 'web.mydigipay.info'];
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
        && !isset($parts['user']) && !isset($parts['pass'])
        && (!isset($parts['port']) || $parts['port'] === 443);
}

function digipay_metadata(array $attempt): array
{
    $raw = $attempt['metadata_json'] ?? null;
    if (!is_string($raw)) {
        return [];
    }
    try {
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    } catch (JsonException) {
        return [];
    }
}

function digipay_create(string $orderId): string
{
    $prepared = transaction(function () use ($orderId): array {
        $rows = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$rows || $rows[0]['payment'] !== 'digipay' || $rows[0]['status'] !== 'awaiting_payment' || $rows[0]['payment_status'] !== 'unpaid') {
            app_error('این سفارش در انتظار پرداخت دیجی‌پی نیست.', 409);
        }
        $order = $rows[0];
        $attempts = commerce_rows('SELECT * FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC,id DESC LIMIT 1', [$orderId]);
        if ($attempts) {
            $metadata = digipay_metadata($attempts[0]);
            if ($attempts[0]['provider'] === 'digipay' && $attempts[0]['status'] === 'pending' && digipay_redirect_valid($metadata['redirectUrl'] ?? null)) {
                return ['existing' => true, 'redirect' => $metadata['redirectUrl']];
            }
            app_error('درخواست دیجی‌پی این سفارش قبلاً ثبت شده است؛ برای جلوگیری از پرداخت تکراری با پشتیبانی پیگیری کنید.', 409);
        }
        $id = uid('digipay');
        $state = bin2hex(random_bytes(32));
        $config = config()['payment']['digipay'];
        $items = [];
        foreach (commerce_order_lines($orderId) as $line) {
            $items[] = ['sellerId' => $config['seller_id'], 'supplierId' => $config['supplier_id'], 'productCode' => $line['variant_id'],
                'brand' => $config['brand'], 'productType' => $config['product_type'], 'count' => (int) $line['quantity'], 'categoryId' => $config['category_id']];
        }
        if (!$items) {
            app_error('اقلام سفارش برای دیجی‌پی موجود نیست.', 409);
        }
        $metadata = ['providerId' => $id, 'stateHash' => hash('sha256', $state), 'products' => array_column($items, 'productCode'),
            'delivery' => ['status' => 'pending']];
        $amount = payment_amount_rial($order);
        commerce_execute("INSERT INTO payment_attempts (id,order_id,provider,authority,amount_rial,status,reference_id,metadata_json,created_at,updated_at) VALUES (?,?,'digipay',NULL,?,'requesting',NULL,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$id, $orderId, $amount, json_value($metadata)]);
        return ['existing' => false, 'id' => $id, 'order' => $order, 'metadata' => $metadata, 'payload' => [
            'amount' => $amount, 'cellNumber' => phone_value($order['customer_phone']), 'providerId' => $id,
            'callbackUrl' => rtrim(config()['app']['base_url'], '/') . '/digipay-callback.php?s=' . $state,
            'basketDetailsDto' => ['basketId' => $orderId, 'items' => $items],
        ]];
    });
    if ($prepared['existing']) {
        return $prepared['redirect'];
    }
    try {
        $response = digipay_call('tickets/business', 11, $prepared['payload']);
        $ticket = $response['data']['ticket'] ?? null;
        $redirect = $response['data']['redirectUrl'] ?? null;
        if (!digipay_success($response) || !is_string($ticket) || $ticket === '' || strlen($ticket) > 2048 || !digipay_redirect_valid($redirect)) {
            app_error('درخواست پرداخت دیجی‌پی پاسخ قطعی قابل استفاده ندارد؛ سفارش پرداخت نشده است و باید پیگیری شود.', 502);
        }
        $stillPayable = transaction(function () use ($prepared, $ticket, $redirect): bool {
            $order = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$prepared['order']['id']])[0];
            $attempt = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$prepared['id']])[0];
            $metadata = digipay_metadata($attempt);
            $metadata['ticket'] = $ticket;
            $metadata['redirectUrl'] = $redirect;
            // A fast callback may have already verified the purchase. Never overwrite that state.
            $status = $attempt['status'] === 'verified' ? 'verified' : 'pending';
            commerce_execute('UPDATE payment_attempts SET status = ?,metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, json_value($metadata), $attempt['id']]);
            return $order['status'] === 'awaiting_payment' && $order['payment_status'] === 'unpaid';
        });
        if (!$stillPayable) {
            app_error('این سفارش دیگر در انتظار پرداخت نیست؛ وضعیت سفارش را بررسی کنید.', 409);
        }
        return $redirect;
    } catch (Throwable $error) {
        // The provider may have issued a ticket despite a network failure. No automatic second ticket.
        commerce_execute("UPDATE payment_attempts SET status = 'request_unknown',updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'requesting'", [$prepared['id']]);
        throw $error;
    }
}

function digipay_callback_scalar(array $input, string $key, int $maximum = 100): string
{
    $value = $input[$key] ?? null;
    if ((!is_string($value) && !is_int($value)) || (string) $value === '' || strlen((string) $value) > $maximum || preg_match('/[\x00-\x20\x7f]/', (string) $value)) {
        app_error('اطلاعات بازگشت دیجی‌پی معتبر نیست.');
    }
    return (string) $value;
}

/** This callback intentionally does not require cookies, a user session, or a CSRF header. */
function handle_digipay_callback(array $input, mixed $state): array
{
    if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state)) {
        app_error('نشانی بازگشت دیجی‌پی معتبر نیست.');
    }
    $providerId = digipay_callback_scalar($input, 'providerId', 64);
    $tracking = digipay_callback_scalar($input, 'trackingCode');
    $amount = digipay_callback_scalar($input, 'amount', 16);
    $typeString = digipay_callback_scalar($input, 'type', 2);
    if (!preg_match('/^[0-9]{1,16}$/D', $amount) || !in_array($typeString, ['0', '5', '11', '13', '24'], true)) {
        app_error('مبلغ یا نوع پرداخت دیجی‌پی معتبر نیست.');
    }
    $prepared = transaction(function () use ($providerId, $tracking, $amount, $typeString, $state): array {
        $found = commerce_rows("SELECT order_id FROM payment_attempts WHERE id = ? AND provider = 'digipay'", [$providerId]);
        if (!$found) {
            app_error('پرداخت دیجی‌پی پیدا نشد.', 404);
        }
        $order = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$found[0]['order_id']])[0];
        $attempt = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$providerId])[0];
        $metadata = digipay_metadata($attempt);
        if ($order['payment'] !== 'digipay' || !is_string($metadata['stateHash'] ?? null) || !hash_equals($metadata['stateHash'], hash('sha256', $state))
            || ($metadata['providerId'] ?? '') !== $providerId || (int) $amount !== (int) $attempt['amount_rial'] || payment_amount_rial($order) !== (int) $attempt['amount_rial']) {
            app_error('اطلاعات بازگشت با پرداخت ثبت‌شده مطابقت ندارد.', 409);
        }
        if (isset($metadata['trackingCode']) && ($metadata['trackingCode'] !== $tracking || ($metadata['type'] ?? null) !== (int) $typeString)) {
            app_error('کد پیگیری بازگشت با پرداخت ثبت‌شده مطابقت ندارد.', 409);
        }
        return ['order' => $order, 'attempt' => $attempt, 'metadata' => $metadata];
    });
    if ($prepared['attempt']['status'] === 'verified') {
        return payment_result($prepared['order'], true, 'پرداخت این سفارش قبلاً تأیید شده است.');
    }
    // Neither posted SUCCESS nor FAILURE establishes the provider's final payment status.
    $response = digipay_call('purchases/verify', (int) $typeString, ['trackingCode' => $tracking, 'providerId' => $providerId]);
    if (!digipay_success($response)) {
        return payment_result($prepared['order'], false, 'دیجی‌پی هنوز پرداخت را به‌طور قطعی تأیید نکرده است؛ پیش از پرداخت مجدد از پشتیبانی پیگیری کنید.');
    }
    $verified = $response['data'];
    if (!is_scalar($verified['providerId'] ?? null) || (string) $verified['providerId'] !== $providerId
        || !is_scalar($verified['trackingCode'] ?? null) || (string) $verified['trackingCode'] !== $tracking
        || (!is_string($verified['amount'] ?? null) && !is_int($verified['amount'] ?? null))
        || !preg_match('/^[0-9]{1,16}$/D', (string) $verified['amount']) || (int) $verified['amount'] !== (int) $prepared['attempt']['amount_rial']) {
        app_error('پاسخ تأیید دیجی‌پی با مبلغ یا شناسهٔ سفارش مطابقت ندارد؛ سفارش نیاز به پیگیری دارد.', 409);
    }
    return transaction(function () use ($prepared, $providerId, $tracking, $typeString): array {
        commerce_rows('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$prepared['order']['id']]);
        $attempt = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$providerId])[0];
        $metadata = digipay_metadata($attempt);
        if (isset($metadata['trackingCode']) && ($metadata['trackingCode'] !== $tracking || ($metadata['type'] ?? null) !== (int) $typeString)) {
            app_error('شناسهٔ تأیید پرداخت با نتیجهٔ ثبت‌شده مطابقت ندارد.', 409);
        }
        // Bind identifiers only after a successful independent provider response.
        $metadata['trackingCode'] = $tracking;
        $metadata['type'] = (int) $typeString;
        commerce_execute('UPDATE payment_attempts SET metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [json_value($metadata), $providerId]);
        return payment_finalize_verified($prepared['order']['id'], $providerId, $tracking);
    });
}

function digipay_delivery_details(string $orderId): ?array
{
    $attempts = commerce_rows("SELECT * FROM payment_attempts WHERE order_id = ? AND provider = 'digipay' AND status = 'verified' ORDER BY created_at DESC,id DESC LIMIT 1", [$orderId]);
    if (!$attempts) {
        return null;
    }
    $delivery = digipay_metadata($attempts[0])['delivery'] ?? [];
    $status = $delivery['status'] ?? 'pending';
    $status = $status === 'reported' ? 'reported' : ($status === 'failed' ? 'failed' : 'pending');
    return ['status' => $status, 'reportedAt' => isset($delivery['reportedAt']) && is_string($delivery['reportedAt']) ? iso_time($delivery['reportedAt']) : null,
        'message' => $status === 'reported' ? 'وضعیت تحویل ثبت شده است.' : ($status === 'failed' ? 'گزارش تحویل تأیید نشده است؛ امکان تلاش دوباره وجود دارد.' : 'گزارش تحویل هنوز تأیید نشده است.')];
}

function payment_deliver(array $input): array
{
    require_permission('orders.edit');
    $orderId = commerce_id($input['orderId'] ?? null, 'سفارش');
    $lease = bin2hex(random_bytes(16));
    $prepared = transaction(function () use ($orderId, $lease): array {
        $orders = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$orders) {
            app_error('سفارش پیدا نشد.', 404);
        }
        $order = $orders[0];
        if ($order['payment'] !== 'digipay' || $order['status'] !== 'completed' || $order['payment_status'] !== 'paid') {
            app_error('اعلام تحویل دیجی‌پی فقط پس از تحویل واقعی و تکمیل سفارش پرداخت‌شده امکان دارد.', 409);
        }
        $attempts = commerce_rows("SELECT * FROM payment_attempts WHERE order_id = ? AND provider = 'digipay' AND status = 'verified' ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE", [$orderId]);
        if (!$attempts) {
            app_error('پرداخت تأییدشدهٔ دیجی‌پی پیدا نشد.', 409);
        }
        $attempt = $attempts[0];
        $metadata = digipay_metadata($attempt);
        $delivery = $metadata['delivery'] ?? [];
        if (($delivery['status'] ?? '') === 'reported') {
            return ['existing' => true];
        }
        if (($delivery['status'] ?? '') === 'reporting' && (int) ($delivery['leaseUntil'] ?? 0) > time()) {
            app_error('ثبت تحویل در حال انجام است؛ کمی بعد وضعیت را بررسی کنید.', 409);
        }
        $type = $metadata['type'] ?? null;
        if (!in_array($type, [0, 5, 11, 13, 24], true) || !is_string($metadata['trackingCode'] ?? null) || !is_array($metadata['products'] ?? null) || !$metadata['products']) {
            app_error('اطلاعات تأیید پرداخت برای اعلام تحویل کامل نیست.', 409);
        }
        $required = in_array($type, [5, 13], true);
        $payload = ['deliveryDate' => $delivery['deliveryDate'] ?? (int) floor(microtime(true) * 1000),
            'invoiceNumber' => $order['number'], 'trackingCode' => $metadata['trackingCode'], 'products' => array_values($metadata['products'])];
        $metadata['delivery'] = ['status' => $required ? 'reporting' : 'reported', 'lease' => $lease, 'leaseUntil' => time() + 90,
            'deliveryDate' => $payload['deliveryDate'], 'reportedAt' => $required ? null : utc_now(), 'required' => $required];
        commerce_execute('UPDATE payment_attempts SET metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [json_value($metadata), $attempt['id']]);
        return ['existing' => !$required, 'id' => $attempt['id'], 'type' => $type, 'payload' => $payload];
    });
    if ($prepared['existing']) {
        return ['orderId' => $orderId, 'deliveryReport' => digipay_delivery_details($orderId)];
    }
    $success = false;
    try {
        $success = digipay_success(digipay_call('purchases/deliver', $prepared['type'], $prepared['payload']));
    } catch (AppError) {
        // Save a generic retryable state without logging any provider response or credentials.
    }
    transaction(function () use ($orderId, $prepared, $lease, $success): void {
        commerce_rows('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        $attempt = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$prepared['id']])[0];
        $metadata = digipay_metadata($attempt);
        if (($metadata['delivery']['lease'] ?? '') !== $lease) {
            return;
        }
        $metadata['delivery']['status'] = $success ? 'reported' : 'failed';
        $metadata['delivery']['reportedAt'] = $success ? utc_now() : null;
        unset($metadata['delivery']['lease'], $metadata['delivery']['leaseUntil']);
        commerce_execute('UPDATE payment_attempts SET metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [json_value($metadata), $attempt['id']]);
        audit('payment.delivery', $orderId, ['reported' => $success]);
    });
    return ['orderId' => $orderId, 'deliveryReport' => digipay_delivery_details($orderId)];
}
