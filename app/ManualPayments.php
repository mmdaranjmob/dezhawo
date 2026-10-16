<?php
declare(strict_types=1);

/** Manual transfers are claims until a permitted staff member confirms the bank receipt. */
function manual_payment_metadata(array $attempt): array
{
    $metadata = json_decode((string) ($attempt['metadata_json'] ?? ''), true);
    return is_array($metadata) ? $metadata : [];
}

/** Only call after authorizing the order owner or a permitted order viewer. */
function manual_payment_details(string $orderId): ?array
{
    $rows = commerce_rows("SELECT a.*,r.expires_at AS reservation_expires_at FROM payment_attempts a JOIN orders o ON o.id = a.order_id LEFT JOIN reservations r ON r.id = o.reservation_id WHERE a.order_id = ? AND a.provider = 'bank_transfer' ORDER BY a.created_at DESC,a.id DESC LIMIT 1", [$orderId]);
    if (!$rows) {
        return null;
    }
    $attempt = $rows[0];
    $metadata = manual_payment_metadata($attempt);
    $bank = is_array($metadata['bank'] ?? null) ? $metadata['bank'] : [];
    $status = $metadata['reviewStatus'] ?? 'awaiting_transfer';
    if (!in_array($status, ['awaiting_transfer', 'pending_review', 'approved', 'rejected', 'cancelled'], true)) {
        $status = 'awaiting_transfer';
    }
    $expiry = $attempt['reservation_expires_at'] ?? null;
    return [
        'holder' => clean($bank['holder'] ?? '', 160),
        'card' => digits($bank['card'] ?? ''),
        'iban' => strtoupper(clean($bank['iban'] ?? '', 26)),
        'bankName' => clean($bank['bankName'] ?? '', 160),
        'instructions' => clean($bank['instructions'] ?? '', 2000),
        'reference' => clean($metadata['reference'] ?? '', 100),
        'note' => clean($metadata['note'] ?? '', 1000),
        'reviewStatus' => $status,
        'expiresAt' => $expiry ? (new DateTimeImmutable($expiry, new DateTimeZone('UTC')))->getTimestamp() * 1000 : null,
    ];
}

/** Creates one immutable account snapshot; repeated calls never renew a reservation. */
function manual_payment_begin(string $orderId): array
{
    return transaction(function () use ($orderId): array {
        $rows = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$rows || $rows[0]['type'] !== 'online' || $rows[0]['payment'] !== 'bank_transfer') {
            app_error('سفارش واریز بانکی پیدا نشد.', 404);
        }
        $order = $rows[0];
        if (!in_array($order['status'], ['awaiting_payment', 'awaiting_review'], true) || !in_array($order['payment_status'], ['unpaid', 'pending_review'], true)) {
            app_error('این سفارش در انتظار واریز یا بررسی نیست.', 409);
        }
        $attempts = commerce_rows('SELECT * FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE', [$orderId]);
        if ($attempts) {
            if ($attempts[0]['provider'] !== 'bank_transfer' || !in_array($attempts[0]['status'], ['created', 'submitted'], true)) {
                app_error('وضعیت واریز این سفارش قابل تغییر نیست.', 409);
            }
        } else {
            $options = payment_options();
            if (!payment_bank_details_valid($options)) {
                app_error('اطلاعات مقصد واریز توسط مدیر تکمیل نشده است.', 503);
            }
            $metadata = [
                'bank' => [
                    'holder' => $options['bankHolder'], 'card' => $options['bankCard'], 'iban' => $options['bankIban'],
                    'bankName' => $options['bankName'], 'instructions' => $options['bankInstructions'],
                ],
                'reviewStatus' => 'awaiting_transfer',
            ];
            commerce_execute("INSERT INTO payment_attempts (id,order_id,authority,amount_rial,status,reference_id,provider,metadata_json,created_at,updated_at) VALUES (?,?,NULL,?,'created',NULL,'bank_transfer',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [uid('transfer'), $orderId, (int) $order['total'] * 10, json_value($metadata)]);
        }
        $details = manual_payment_details($orderId);
        if (!$details) {
            app_error('اطلاعات واریز سفارش در دسترس نیست.', 409);
        }
        return $details;
    });
}

function manual_payment_reject_override(array $input): void
{
    foreach (['paid', 'status', 'payment', 'paymentStatus', 'payment_status', 'amount', 'amountRial', 'total', 'subtotal', 'userId', 'roleId'] as $field) {
        if (array_key_exists($field, $input)) {
            app_error('مبلغ، مالک سفارش و وضعیت پرداخت فقط توسط سرور تعیین می‌شوند.');
        }
    }
}

function manual_payment_submit(array $input): array
{
    $user = require_user();
    manual_payment_reject_override($input);
    $id = commerce_id($input['orderId'] ?? null, 'سفارش');
    $reference = clean($input['reference'] ?? '', 100);
    $note = clean($input['note'] ?? '', 1000);
    if ($reference === '' || mb_strlen((string) ($input['reference'] ?? '')) > 100 || mb_strlen((string) ($input['note'] ?? '')) > 1000) {
        app_error('شمارهٔ پیگیری واریز را تا ۱۰۰ نویسه و توضیح را تا ۱۰۰۰ نویسه وارد کنید.');
    }
    return transaction(function () use ($user, $id, $reference, $note): array {
        $orders = commerce_rows('SELECT * FROM orders WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $user['id']]);
        if (!$orders || $orders[0]['type'] !== 'online' || $orders[0]['payment'] !== 'bank_transfer') {
            app_error('سفارش واریز بانکی پیدا نشد.', 404);
        }
        $order = $orders[0];
        $attempts = commerce_rows("SELECT * FROM payment_attempts WHERE order_id = ? AND provider = 'bank_transfer' ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE", [$id]);
        if (!$attempts) {
            app_error('اطلاعات مقصد واریز این سفارش پیدا نشد.', 409);
        }
        $attempt = $attempts[0];
        $metadata = manual_payment_metadata($attempt);
        if ($order['status'] === 'awaiting_review' && $order['payment_status'] === 'pending_review' && $attempt['status'] === 'submitted') {
            if (($metadata['reference'] ?? '') !== $reference || ($metadata['note'] ?? '') !== $note) {
                app_error('مشخصات واریز قبلاً برای بررسی ارسال شده است؛ برای اصلاح آن تیکت ثبت کنید.', 409);
            }
            return ['orderId' => $id, 'status' => 'awaiting_review', 'paymentStatus' => 'pending_review', 'manualPayment' => manual_payment_details($id)];
        }
        if ($order['status'] !== 'awaiting_payment' || $order['payment_status'] !== 'unpaid' || $attempt['status'] !== 'created') {
            app_error('این سفارش امکان ثبت واریز جدید ندارد.', 409);
        }
        // An already-made transfer can be reported after expiry; this does not reserve goods again.
        $metadata['reference'] = $reference;
        $metadata['note'] = $note;
        $metadata['submittedAt'] = iso_time(utc_now());
        $metadata['reviewStatus'] = 'pending_review';
        commerce_execute("UPDATE payment_attempts SET status = 'submitted',metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?", [json_value($metadata), $attempt['id']]);
        commerce_execute("UPDATE orders SET status = 'awaiting_review',payment_status = 'pending_review',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
        audit('transfer.submitted', $id, []);
        return ['orderId' => $id, 'status' => 'awaiting_review', 'paymentStatus' => 'pending_review', 'manualPayment' => manual_payment_details($id)];
    });
}

function manual_payment_review(array $input): array
{
    $user = require_permission('orders.edit');
    require_permission('finance.view');
    manual_payment_reject_override($input);
    $id = commerce_id($input['orderId'] ?? null, 'سفارش');
    $decision = $input['decision'] ?? '';
    if (!in_array($decision, ['approve', 'reject'], true)) {
        app_error('نتیجهٔ بررسی باید تأیید یا رد واریز باشد.');
    }
    $note = clean($input['note'] ?? '', 1000);
    if (mb_strlen((string) ($input['note'] ?? '')) > 1000) {
        app_error('یادداشت بررسی باید حداکثر ۱۰۰۰ نویسه باشد.');
    }
    return transaction(function () use ($id, $decision, $note, $user): array {
        $orders = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$orders || $orders[0]['type'] !== 'online' || $orders[0]['payment'] !== 'bank_transfer') {
            app_error('سفارش واریز بانکی پیدا نشد.', 404);
        }
        $order = $orders[0];
        $attempts = commerce_rows("SELECT * FROM payment_attempts WHERE order_id = ? AND provider = 'bank_transfer' ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE", [$id]);
        if (!$attempts) {
            app_error('درخواست واریز پیدا نشد.', 404);
        }
        $attempt = $attempts[0];
        $metadata = manual_payment_metadata($attempt);
        // Repeat clicks are harmless; a later opposite decision cannot reverse a completed review.
        if ($decision === 'approve' && $attempt['status'] === 'verified' && ($metadata['reviewStatus'] ?? '') === 'approved') {
            return payment_result($order, true, 'واریز این سفارش قبلاً تأیید شده است.') + ['manualPayment' => manual_payment_details($id)];
        }
        if ($decision === 'reject' && $attempt['status'] === 'rejected' && ($metadata['reviewStatus'] ?? '') === 'rejected') {
            return payment_result($order, false, 'واریز این سفارش قبلاً رد شده است.') + ['manualPayment' => manual_payment_details($id)];
        }
        if ($order['status'] !== 'awaiting_review' || $order['payment_status'] !== 'pending_review' || $attempt['status'] !== 'submitted' || !is_string($metadata['reference'] ?? null) || $metadata['reference'] === '') {
            app_error('این سفارش واریز ثبت‌شدهٔ قابل بررسی ندارد؛ وضعیت سفارش را دوباره بررسی کنید.', 409);
        }
        $metadata['reviewStatus'] = $decision === 'approve' ? 'approved' : 'rejected';
        $metadata['reviewedBy'] = $user['id'];
        $metadata['reviewedAt'] = iso_time(utc_now());
        $metadata['reviewNote'] = $note;
        if ($decision === 'approve') {
            // The shared finalizer rechecks real stock, handles expired reservations and deducts once.
            $result = payment_finalize_verified($id, $attempt['id'], clean($metadata['reference'], 100));
            commerce_execute('UPDATE payment_attempts SET metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?', [json_value($metadata), $attempt['id']]);
            audit('transfer.approved', $id, ['status' => $result['status']]);
            return $result + ['manualPayment' => manual_payment_details($id)];
        }
        commerce_lock_variants(array_column(commerce_order_lines($id), 'variant_id'));
        commerce_execute("UPDATE payment_attempts SET status = 'rejected',metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?", [json_value($metadata), $attempt['id']]);
        commerce_execute("UPDATE orders SET status = 'payment_failed',payment_status = 'failed',updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
        commerce_execute("UPDATE reservations SET status = 'released',expires_at = UTC_TIMESTAMP() WHERE id = ?", [$order['reservation_id']]);
        $order['status'] = 'payment_failed';
        $order['payment_status'] = 'failed';
        audit('transfer.rejected', $id, []);
        return payment_result($order, false, 'واریز تأیید نشد؛ موجودی برای این سفارش کسر نشده است.') + ['manualPayment' => manual_payment_details($id)];
    });
}

/** Caller holds the order lock and has already established that stock was never allocated. */
function manual_payment_cancel_locked(string $orderId): void
{
    $attempts = commerce_rows("SELECT * FROM payment_attempts WHERE order_id = ? AND provider = 'bank_transfer' ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE", [$orderId]);
    if (!$attempts || !in_array($attempts[0]['status'], ['created', 'submitted'], true)) {
        return;
    }
    $attempt = $attempts[0];
    $metadata = manual_payment_metadata($attempt);
    $metadata['reviewStatus'] = 'cancelled';
    commerce_execute("UPDATE payment_attempts SET status = 'cancelled',metadata_json = ?,updated_at = UTC_TIMESTAMP() WHERE id = ?", [json_value($metadata), $attempt['id']]);
}

function manual_payment_action(string $action, array $input): mixed
{
    return match ($action) {
        'transfer.submit' => manual_payment_submit($input),
        'transfer.review' => manual_payment_review($input),
        default => app_error('عملیات واریز پشتیبانی نمی‌شود.', 404),
    };
}
