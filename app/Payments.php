<?php
declare(strict_types=1);

/** Explicit integration-test switch; a production config can never enable this gateway. */
function payment_is_stub(): bool
{
    $configuration = config();
    return ($configuration['app']['env'] ?? 'production') === 'testing' && ($configuration['testing']['payment_stub'] ?? false) === true;
}

function payment_require_configured(): void
{
    if (payment_is_stub()) {
        return;
    }
    $configuration = config();
    if (($configuration['payment']['provider'] ?? '') !== 'zarinpal' || !preg_match('/^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}$/D', (string) ($configuration['payment']['merchant_id'] ?? ''))) {
        app_error('درگاه پرداخت هنوز توسط مدیر پیکربندی نشده است.', 503);
    }
    $base = (string) ($configuration['app']['base_url'] ?? '');
    $parts = parse_url($base);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        app_error('نشانی HTTPS فروشگاه برای بازگشت از درگاه تنظیم نشده است.', 503);
    }
    if (!function_exists('curl_init')) {
        app_error('افزونهٔ cURL برای پرداخت روی هاست فعال نیست.', 503);
    }
}

function payment_gateway_host(): string
{
    return (config()['payment']['sandbox'] ?? false) === true ? 'sandbox.zarinpal.com' : 'api.zarinpal.com';
}

function payment_redirect(string $authority): string
{
    if (payment_is_stub()) {
        return rtrim((string) config()['app']['base_url'], '/') . '/payment-callback.php?Authority=' . rawurlencode($authority) . '&Status=OK';
    }
    $host = (config()['payment']['sandbox'] ?? false) === true ? 'sandbox.zarinpal.com' : 'www.zarinpal.com';
    return 'https://' . $host . '/pg/StartPay/' . rawurlencode($authority);
}

function payment_gateway_call(string $operation, array $payload): array
{
    if (!in_array($operation, ['request', 'verify'], true)) {
        throw new LogicException('Invalid gateway operation');
    }
    payment_require_configured();
    $handle = curl_init('https://' . payment_gateway_host() . '/pg/v4/payment/' . $operation . '.json');
    curl_setopt_array($handle, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 35, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failed = curl_errno($handle) !== 0;
    curl_close($handle);
    if ($failed || !is_string($response) || $status < 200 || $status >= 300 || strlen($response) > 1048576) {
        app_error('ارتباط امن با درگاه برقرار نشد؛ وضعیت سفارش را پیش از پرداخت مجدد بررسی کنید.', 502);
    }
    try {
        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        app_error('پاسخ درگاه قابل بررسی نیست؛ وضعیت سفارش را بررسی کنید.', 502);
    }
    if (!is_array($decoded)) {
        app_error('پاسخ درگاه معتبر نیست.', 502);
    }
    return $decoded;
}

function payment_create(string $orderId): string
{
    payment_require_configured();
    $prepared = transaction(function () use ($orderId): array {
        $rows = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$rows || $rows[0]['status'] !== 'awaiting_payment' || $rows[0]['payment_status'] !== 'unpaid') {
            app_error('این سفارش در انتظار پرداخت نیست.');
        }
        $order = $rows[0];
        $attempts = commerce_rows('SELECT * FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC,id DESC LIMIT 1', [$orderId]);
        if ($attempts) {
            $latest = $attempts[0];
            if ($latest['status'] === 'pending' && $latest['authority']) {
                return ['order' => $order, 'attempt' => $latest, 'existing' => true];
            }
            // The provider might have accepted an uncertain request: do not silently request another payment.
            app_error('درخواست پرداخت این سفارش قبلاً ثبت شده است؛ وضعیت آن را از سفارش‌ها پیگیری کنید.', 409);
        }
        $amountRial = (int) $order['total'] * 10;
        if ($amountRial < 10000 && !payment_is_stub()) {
            app_error('مبلغ سفارش از حداقل قابل پرداخت درگاه کمتر است.');
        }
        $attempt = ['id' => uid('payment'), 'amount_rial' => $amountRial];
        commerce_execute("INSERT INTO payment_attempts (id,order_id,authority,amount_rial,status,reference_id,created_at,updated_at) VALUES (?,?,NULL,?,'requesting',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$attempt['id'], $orderId, $amountRial]);
        return ['order' => $order, 'attempt' => $attempt, 'existing' => false];
    });
    if ($prepared['existing']) {
        return payment_redirect($prepared['attempt']['authority']);
    }
    try {
        if (payment_is_stub()) {
            $authority = 'TEST_' . bin2hex(random_bytes(24));
        } else {
            $configuration = config();
            $response = payment_gateway_call('request', [
                'merchant_id' => $configuration['payment']['merchant_id'], 'amount' => $prepared['attempt']['amount_rial'],
                'description' => 'سفارش دژاوو ' . $prepared['order']['number'],
                'callback_url' => rtrim($configuration['app']['base_url'], '/') . '/payment-callback.php',
                'metadata' => ['mobile' => $prepared['order']['customer_phone']],
            ]);
            if ((int) ($response['data']['code'] ?? 0) !== 100 || !preg_match('/^[A-Za-z0-9]{16,100}$/D', (string) ($response['data']['authority'] ?? ''))) {
                app_error('درگاه درخواست پرداخت را نپذیرفت؛ سفارش پرداخت نشده است.', 502);
            }
            $authority = $response['data']['authority'];
        }
        $stillPayable = transaction(function () use ($orderId, $authority, $prepared): bool {
            // Consistent order -> attempt locking also used by callback finalization.
            $locked = commerce_rows('SELECT status,payment_status FROM orders WHERE id = ? FOR UPDATE', [$orderId])[0];
            commerce_execute("UPDATE payment_attempts SET authority = ?,status = 'pending',updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'requesting'", [$authority, $prepared['attempt']['id']]);
            return $locked['status'] === 'awaiting_payment' && $locked['payment_status'] === 'unpaid';
        });
        if (!$stillPayable) {
            app_error('این سفارش دیگر در انتظار پرداخت نیست؛ به صفحهٔ سفارش‌ها برگردید.', 409);
        }
        return payment_redirect($authority);
    } catch (Throwable $error) {
        transaction(function () use ($orderId, $prepared): void {
            $order = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId])[0];
            commerce_lock_variants(array_column(commerce_order_lines($orderId), 'variant_id'));
            commerce_execute("UPDATE payment_attempts SET status = 'failed',updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'requesting'", [$prepared['attempt']['id']]);
            if ($order['status'] === 'awaiting_payment' && $order['payment_status'] === 'unpaid') {
                commerce_execute("UPDATE orders SET status = 'payment_failed',payment_status = 'failed',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$orderId]);
                commerce_execute("UPDATE reservations SET status = 'released',expires_at = UTC_TIMESTAMP() WHERE id = ?", [$order['reservation_id']]);
            }
        });
        throw $error;
    }
}

function payment_result(array $order, bool $success, string $message): array
{
    return ['success' => $success, 'orderId' => $order['id'], 'number' => $order['number'], 'status' => $order['status'], 'paymentStatus' => $order['payment_status'], 'message' => $message];
}

function handle_payment_callback(array $query): array
{
    $authority = $query['Authority'] ?? null;
    if (!is_string($authority) || !preg_match('/^[A-Za-z0-9_]{16,100}$/D', $authority)) {
        app_error('شناسهٔ پرداخت معتبر نیست.');
    }
    $attemptRows = commerce_rows('SELECT * FROM payment_attempts WHERE authority = ?', [$authority]);
    if (!$attemptRows) {
        app_error('پرداخت پیدا نشد.', 404);
    }
    $attempt = $attemptRows[0];
    $order = commerce_rows('SELECT * FROM orders WHERE id = ?', [$attempt['order_id']])[0];
    if ($attempt['status'] === 'verified') {
        return payment_result($order, in_array($order['payment_status'], ['paid', 'refund_pending'], true), $order['payment_status'] === 'refund_pending' ? 'پرداخت ثبت شده است و بازپرداخت باید توسط پشتیبانی پیگیری شود.' : 'پرداخت این سفارش قبلاً تأیید شده است.');
    }
    if (($query['Status'] ?? '') !== 'OK') {
        return transaction(function () use ($order, $attempt): array {
            $locked = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order['id']])[0];
            $payment = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$attempt['id']])[0];
            if ($payment['status'] === 'verified') {
                return payment_result($locked, true, 'پرداخت این سفارش قبلاً تأیید شده است.');
            }
            commerce_lock_variants(array_column(commerce_order_lines($order['id']), 'variant_id'));
            commerce_execute("UPDATE payment_attempts SET status = 'failed',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$attempt['id']]);
            if ($locked['status'] === 'awaiting_payment' && $locked['payment_status'] === 'unpaid') {
                commerce_execute("UPDATE orders SET status = 'payment_failed',payment_status = 'failed',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$locked['id']]);
                commerce_execute("UPDATE reservations SET status = 'released',expires_at = UTC_TIMESTAMP() WHERE id = ?", [$locked['reservation_id']]);
                $locked['status'] = 'payment_failed';
                $locked['payment_status'] = 'failed';
            }
            return payment_result($locked, false, 'پرداخت تکمیل نشد؛ مبلغ کالا تأیید نشده است.');
        });
    }
    if (payment_is_stub()) {
        if (!str_starts_with($authority, 'TEST_')) {
            app_error('پرداخت آزمایشی معتبر نیست.');
        }
        // Private QA can simulate a provider rejecting verification. This is never read in production.
        if ((config()['testing']['payment_stub_failure'] ?? false) === true) {
            return payment_result($order, false, 'درگاه آزمایشی پرداخت را تأیید نکرد؛ سفارش پرداخت‌شده محسوب نمی‌شود.');
        }
        $reference = 'T' . substr(hash('sha256', $authority), 0, 24);
    } else {
        // Browser-supplied Status=OK never establishes payment: independently verify the stored rial amount.
        $response = payment_gateway_call('verify', [
            'merchant_id' => config()['payment']['merchant_id'], 'amount' => (int) $attempt['amount_rial'], 'authority' => $authority,
        ]);
        if (!in_array((int) ($response['data']['code'] ?? 0), [100, 101], true) || empty($response['data']['ref_id'])) {
            return payment_result($order, false, 'درگاه پرداخت را تأیید نکرد؛ سفارش پرداخت‌شده محسوب نمی‌شود. در صورت کسر وجه با پشتیبانی تماس بگیرید.');
        }
        $reference = clean((string) $response['data']['ref_id'], 100);
    }
    return transaction(function () use ($order, $attempt, $reference): array {
        $locked = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order['id']])[0];
        $payment = commerce_rows('SELECT * FROM payment_attempts WHERE id = ? FOR UPDATE', [$attempt['id']])[0];
        if ($payment['status'] === 'verified') {
            return payment_result($locked, true, 'پرداخت این سفارش قبلاً تأیید شده است.');
        }
        if ((int) $payment['amount_rial'] !== (int) $locked['total'] * 10) {
            app_error('مبلغ پرداخت با سفارش مطابقت ندارد.', 409);
        }
        commerce_execute("UPDATE payment_attempts SET status = 'verified',reference_id = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reference, $payment['id']]);
        if ($locked['status'] === 'cancelled' || $locked['payment_status'] === 'refund_pending') {
            commerce_execute("UPDATE orders SET payment_status = 'refund_pending',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$locked['id']]);
            $locked['payment_status'] = 'refund_pending';
            return payment_result($locked, true, 'وجه پرداخت شد، اما سفارش لغو شده است؛ برای بازپرداخت با پشتیبانی تماس بگیرید.');
        }
        // Defense against an unexpected second authority for a previously paid order.
        if ($locked['payment_status'] === 'paid') {
            return payment_result($locked, true, 'پرداخت این سفارش قبلاً ثبت شده است.');
        }
        $lines = commerce_order_lines($locked['id']);
        $variants = commerce_lock_variants(array_column($lines, 'variant_id'));
        $shortage = false;
        foreach ($lines as $line) {
            $variant = $variants[$line['variant_id']];
            if (!(int) $variant['enabled'] || !(int) $variant['product_enabled'] || $variant['category'] !== $line['category'] || ($line['category'] !== 'digital' && (int) $variant['stock'] - commerce_reserved($line['variant_id'], $locked['reservation_id']) < (int) $line['quantity'])) {
                $shortage = true;
                break;
            }
        }
        $status = $shortage ? 'payment_review' : 'paid';
        if (!$shortage) {
            $systemUser = ['id' => null, 'name' => 'تأیید درگاه'];
            foreach ($lines as $line) {
                if ($line['category'] !== 'digital') {
                    commerce_execute('UPDATE product_variants SET stock = stock - ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $line['quantity'], $line['variant_id']]);
                    commerce_stock_movement($variants[$line['variant_id']], -(int) $line['quantity'], 'فروش اینترنتی ' . $locked['number'], $systemUser, $locked['id']);
                }
            }
        }
        commerce_execute("UPDATE orders SET status = ?,payment_status = 'paid',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$status, $locked['id']]);
        commerce_execute("UPDATE reservations SET status = ?,expires_at = UTC_TIMESTAMP() WHERE id = ?", [$shortage ? 'released' : 'consumed', $locked['reservation_id']]);
        // Remove only the paid snapshot, preserving later edits and carts attached to a newer reservation.
        foreach ($lines as $line) {
            commerce_execute("DELETE c FROM cart_items c JOIN reservations r ON r.cart_key = c.cart_key WHERE r.id = ? AND c.variant_id = ? AND c.quantity = ? AND c.updated_at <= r.created_at AND NOT EXISTS (SELECT 1 FROM reservations newer WHERE newer.cart_key = r.cart_key AND newer.id <> r.id AND newer.status IN ('active','payment_pending') AND newer.expires_at > UTC_TIMESTAMP())", [$locked['reservation_id'], $line['variant_id'], (int) $line['quantity']]);
        }
        $locked['status'] = $status;
        $locked['payment_status'] = 'paid';
        audit('payment.verified', $locked['id'], ['reference' => $reference, 'status' => $status]);
        $hasDigital = count(array_filter($lines, fn($line) => $line['category'] === 'digital')) > 0;
        return payment_result($locked, true, $shortage ? 'پرداخت تأیید شد، اما کالا پس از پایان رزرو قابل تخصیص نیست؛ سفارش برای تأمین کالا یا بازپرداخت به پشتیبانی ارجاع شد.' : 'پرداخت تأیید و سفارش ثبت شد.' . ($hasDigital ? ' سفارش دیجیتال پس از بررسی کارکنان فعال می‌شود.' : ''));
    });
}
