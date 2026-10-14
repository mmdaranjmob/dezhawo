<?php
declare(strict_types=1);

/** Authoritative commerce operations. All monetary values are integer tomans. */
function commerce_id(mixed $value, string $label = 'شناسه'): string
{
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)) {
        app_error($label . ' معتبر نیست.');
    }
    return $value;
}

function commerce_rows(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function commerce_execute(string $sql, array $params = []): int
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->rowCount();
}

/** Lock order is products sorted by ID, then variants sorted by ID. */
function commerce_lock_variants(array $variantIds): array
{
    $ids = array_values(array_unique(array_map(fn($id) => commerce_id($id, 'گزینهٔ محصول'), $variantIds)));
    sort($ids, SORT_STRING);
    if (!$ids) {
        return [];
    }
    if (count($ids) > 100) {
        app_error('تعداد اقلام بیش از حد مجاز است.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $parents = commerce_rows("SELECT DISTINCT product_id FROM product_variants WHERE id IN ($placeholders) ORDER BY product_id", $ids);
    $productIds = array_column($parents, 'product_id');
    foreach ($productIds as $productId) {
        commerce_rows('SELECT id FROM products WHERE id = ? FOR UPDATE', [$productId]);
    }
    $locked = [];
    foreach ($ids as $id) {
        $rows = commerce_rows('SELECT v.*, p.title, p.category, p.price AS product_price, p.cost AS product_cost, p.enabled AS product_enabled FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ? FOR UPDATE', [$id]);
        if (!$rows) {
            app_error('یکی از گزینه‌های محصول پیدا نشد.');
        }
        $locked[$id] = $rows[0];
    }
    return $locked;
}

/** Called while holding the corresponding variant lock. */
function commerce_reserved(string $variantId, ?string $excludeReservationId = null): int
{
    $sql = "SELECT COALESCE(SUM(i.quantity),0) AS quantity FROM reservation_items i JOIN reservations r ON r.id = i.reservation_id WHERE i.variant_id = ? AND r.status IN ('active','payment_pending') AND r.expires_at > UTC_TIMESTAMP()";
    $params = [$variantId];
    if ($excludeReservationId !== null) {
        $sql .= ' AND r.id <> ?';
        $params[] = $excludeReservationId;
    }
    return (int) commerce_rows($sql, $params)[0]['quantity'];
}

function commerce_stock_movement(array $variant, int $delta, string $reason, array $user, ?string $orderId = null): void
{
    commerce_execute('INSERT INTO stock_movements (id,product_id,variant_id,product_title,variant_name,delta,reason,user_id,user_name,order_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())', [
        uid('move'), $variant['product_id'], $variant['id'], $variant['title'], $variant['name'], $delta,
        clean($reason, 300), $user['id'] ?? null, clean($user['name'] ?? 'سیستم', 100), $orderId,
    ]);
}

function commerce_cart_lines(string $key): array
{
    return commerce_rows('SELECT product_id,variant_id,quantity FROM cart_items WHERE cart_key = ? ORDER BY variant_id', [$key]);
}

function commerce_active_reservations(string $key): array
{
    return commerce_rows("SELECT id FROM reservations WHERE cart_key = ? AND status = 'active' ORDER BY id", [$key]);
}

function commerce_reservation_variant_ids(array $reservations): array
{
    $ids = [];
    foreach ($reservations as $reservation) {
        foreach (commerce_rows('SELECT variant_id FROM reservation_items WHERE reservation_id = ?', [$reservation['id']]) as $row) {
            $ids[] = $row['variant_id'];
        }
    }
    return $ids;
}

function commerce_release_active_locked(string $key): void
{
    commerce_execute("UPDATE reservations SET status = 'released', expires_at = UTC_TIMESTAMP() WHERE cart_key = ? AND status = 'active'", [$key]);
}

function commerce_assert_cart_editable(string $key): void
{
    $pending = commerce_rows("SELECT id FROM reservations WHERE cart_key = ? AND status = 'payment_pending' AND expires_at > UTC_TIMESTAMP() LIMIT 1", [$key]);
    if ($pending) {
        app_error('درخواست پرداخت این سبد هنوز باز است؛ همان سفارش را پرداخت کنید یا پس از پایان رزرو سبد را تغییر دهید.', 409);
    }
}

function commerce_check_line(array $variant, string $productId, int $quantity, ?string $excludeReservation = null): array
{
    if ($variant['product_id'] !== $productId || !(int) $variant['enabled'] || !(int) $variant['product_enabled']) {
        app_error('محصول یا گزینهٔ انتخاب‌شده قابل فروش نیست.');
    }
    if ($variant['category'] !== 'digital' && (int) $variant['stock'] - commerce_reserved($variant['id'], $excludeReservation) < $quantity) {
        app_error('موجودی قابل فروش کافی نیست؛ رزروهای سایت نیز محاسبه شده‌اند.', 409);
    }
    $price = (int) ($variant['price'] === null ? $variant['product_price'] : $variant['price']);
    if ($price < 1 || $price > 1000000000000) {
        app_error('قیمت این محصول معتبر نیست.');
    }
    return [
        'productId' => $productId, 'variantId' => $variant['id'], 'title' => $variant['title'],
        'variantName' => $variant['name'], 'category' => $variant['category'], 'quantity' => $quantity,
        'unitPrice' => $price, 'unitCost' => (int) $variant['product_cost'],
    ];
}

function commerce_cart_update(array $input, bool $add): array
{
    $productId = commerce_id($input['productId'] ?? null, 'محصول');
    $variantId = commerce_id($input['variantId'] ?? null, 'گزینهٔ محصول');
    $quantity = int_value($input['quantity'] ?? null, 'تعداد', $add ? 1 : 0, 9999);
    $key = cart_key();
    return transaction(function () use ($productId, $variantId, $quantity, $key, $add): array {
        $cart = commerce_cart_lines($key);
        $reservations = commerce_active_reservations($key);
        $variants = commerce_lock_variants(array_merge([$variantId], array_column($cart, 'variant_id'), commerce_reservation_variant_ids($reservations)));
        commerce_assert_cart_editable($key);
        $old = 0;
        foreach ($cart as $line) {
            if ($line['variant_id'] === $variantId) {
                if ($line['product_id'] !== $productId) {
                    app_error('گزینهٔ انتخاب‌شده با محصول مطابقت ندارد.');
                }
                $old = (int) $line['quantity'];
            }
        }
        $next = $add ? $old + $quantity : $quantity;
        if ($next > 9999 || (!$old && $next > 0 && count($cart) >= 50)) {
            app_error('تعداد اقلام سبد بیش از حد مجاز است.');
        }
        commerce_release_active_locked($key);
        if ($next > 0) {
            commerce_check_line($variants[$variantId], $productId, $next);
            commerce_execute('INSERT INTO cart_items (cart_key,product_id,variant_id,quantity,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity),updated_at = UTC_TIMESTAMP()', [$key, $productId, $variantId, $next]);
        } else {
            if ($variants[$variantId]['product_id'] !== $productId) {
                app_error('گزینهٔ انتخاب‌شده با محصول مطابقت ندارد.');
            }
            commerce_execute('DELETE FROM cart_items WHERE cart_key = ? AND variant_id = ?', [$key, $variantId]);
        }
        return ['productId' => $productId, 'variantId' => $variantId, 'quantity' => $next];
    });
}

/** Caller owns the transaction. Reservations contain server-calculated snapshots. */
function commerce_reserve_locked(string $key, ?array $user): array
{
    $cart = commerce_cart_lines($key);
    if (!$cart) {
        app_error('سبد خرید خالی است.');
    }
    if (count($cart) > 50) {
        app_error('تعداد اقلام سبد بیش از حد مجاز است.');
    }
    $old = commerce_active_reservations($key);
    $variants = commerce_lock_variants(array_merge(array_column($cart, 'variant_id'), commerce_reservation_variant_ids($old)));
    commerce_assert_cart_editable($key);
    commerce_release_active_locked($key);
    $lines = [];
    foreach ($cart as $row) {
        $lines[] = commerce_check_line($variants[$row['variant_id']], $row['product_id'], (int) $row['quantity']);
    }
    $id = uid('reservation');
    commerce_execute("INSERT INTO reservations (id,cart_key,user_id,order_id,status,expires_at,created_at) VALUES (?,?,?,NULL,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),UTC_TIMESTAMP())", [$id, $key, $user['id'] ?? null]);
    foreach ($lines as $line) {
        commerce_execute('INSERT INTO reservation_items (reservation_id,product_id,variant_id,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?)', [$id, $line['productId'], $line['variantId'], $line['quantity'], $line['unitPrice'], $line['unitCost']]);
    }
    $expiry = commerce_rows('SELECT expires_at FROM reservations WHERE id = ?', [$id])[0]['expires_at'];
    return ['id' => $id, 'expiresAt' => (new DateTimeImmutable($expiry, new DateTimeZone('UTC')))->getTimestamp() * 1000, 'lines' => $lines];
}

function commerce_reserve(): array
{
    $key = cart_key();
    $user = current_user();
    $reservation = transaction(fn() => commerce_reserve_locked($key, $user));
    if (!can('finance.view', $user)) {
        foreach ($reservation['lines'] as &$line) {
            unset($line['unitCost']);
        }
        unset($line);
    }
    return $reservation;
}

function commerce_release(): array
{
    $key = cart_key();
    return transaction(function () use ($key): array {
        $old = commerce_active_reservations($key);
        commerce_lock_variants(commerce_reservation_variant_ids($old));
        commerce_release_active_locked($key);
        return ['released' => true];
    });
}

function commerce_totals(array $lines, int $shippingFee = 0): array
{
    $subtotal = 0;
    foreach ($lines as $line) {
        $subtotal += $line['unitPrice'] * $line['quantity'];
        if ($subtotal > 1000000000000) {
            app_error('مبلغ سفارش از سقف مجاز بیشتر است.');
        }
    }
    if ($subtotal < 1 || $subtotal + $shippingFee > 1000000000000) {
        app_error('مبلغ سفارش معتبر نیست.');
    }
    return ['subtotal' => $subtotal, 'shippingFee' => $shippingFee, 'total' => $subtotal + $shippingFee];
}

function commerce_insert_items(string $orderId, array $lines): void
{
    foreach ($lines as $line) {
        commerce_execute('INSERT INTO order_items (order_id,product_id,variant_id,title,variant_name,category,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?,?,?,?)', [$orderId, $line['productId'], $line['variantId'], $line['title'], $line['variantName'], $line['category'], $line['quantity'], $line['unitPrice'], $line['unitCost']]);
    }
}

function commerce_order_number(string $prefix): string
{
    return $prefix . '-' . gmdate('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function commerce_checkout(array $input): array
{
    $user = require_user();
    foreach (['paid', 'demo', 'status', 'paymentMode', 'payment_status', 'paymentStatus', 'total', 'subtotal', 'payment'] as $field) {
        if (array_key_exists($field, $input)) {
            app_error('مبلغ و وضعیت پرداخت فقط توسط سرور تعیین می‌شوند.');
        }
    }
    payment_require_configured();
    $customer = $input['customer'] ?? [];
    if (!is_array($customer)) {
        app_error('اطلاعات مشتری معتبر نیست.');
    }
    $name = clean($customer['name'] ?? '', 100);
    if (mb_strlen($name) < 2) {
        app_error('نام گیرنده را وارد کنید.');
    }
    $phone = phone_value($user['phone']);
    if (isset($customer['phone']) && $customer['phone'] !== '' && phone_value($customer['phone']) !== $phone) {
        app_error('شمارهٔ سفارش باید شمارهٔ تأییدشدهٔ حساب باشد.');
    }
    $key = cart_key();
    $result = transaction(function () use ($input, $customer, $name, $phone, $user, $key): array {
        // A repeated submit must not create another payable order for the same reserved cart.
        $pending = commerce_rows("SELECT o.* FROM orders o JOIN reservations r ON r.id = o.reservation_id WHERE r.cart_key = ? AND o.user_id = ? AND o.status = 'awaiting_payment' AND r.status = 'payment_pending' AND r.expires_at > UTC_TIMESTAMP() ORDER BY o.created_at DESC LIMIT 1", [$key, $user['id']]);
        if ($pending) {
            $cartSnapshot = array_map(fn($row) => [$row['product_id'], $row['variant_id'], (int) $row['quantity']], commerce_cart_lines($key));
            $orderSnapshot = array_map(fn($row) => [$row['product_id'], $row['variant_id'], (int) $row['quantity']], commerce_order_lines($pending[0]['id']));
            $shippingMatches = $pending[0]['shipping_id'] === null || $pending[0]['shipping_id'] === ($input['shippingId'] ?? '');
            $customerMatches = $pending[0]['customer_name'] === $name;
            if ($pending[0]['telegram_username'] !== null) {
                $customerMatches = $customerMatches && $pending[0]['telegram_username'] === '@' . ltrim(clean($customer['telegramUsername'] ?? '', 33), '@');
            }
            $addressMatches = true;
            if ($pending[0]['address_json'] !== null) {
                $source = $input['address'] ?? [];
                if (!is_array($source)) {
                    app_error('آدرس گیرنده معتبر نیست.');
                }
                $candidate = ['province' => clean($source['province'] ?? '', 80), 'city' => clean($source['city'] ?? '', 80), 'line' => clean($source['line'] ?? '', 1000), 'postalCode' => digits($source['postalCode'] ?? '')];
                $addressMatches = json_decode($pending[0]['address_json'], true) === $candidate;
            }
            if ($cartSnapshot !== $orderSnapshot || !$shippingMatches || !$customerMatches || !$addressMatches) {
                app_error('درخواست پرداخت قبلی هنوز باز است؛ همان سفارش را تکمیل کنید یا پس از پایان رزرو سفارش جدید ثبت کنید.', 409);
            }
            return ['id' => $pending[0]['id'], 'number' => $pending[0]['number']];
        }
        $reservation = commerce_reserve_locked($key, $user);
        $lines = $reservation['lines'];
        $physical = count(array_filter($lines, fn($line) => $line['category'] !== 'digital')) > 0;
        $digital = count(array_filter($lines, fn($line) => $line['category'] === 'digital')) > 0;
        $telegram = null;
        if ($digital) {
            $telegram = clean($customer['telegramUsername'] ?? '', 33);
            if (!preg_match('/^@?[A-Za-z][A-Za-z0-9_]{4,31}$/D', $telegram)) {
                app_error('برای سفارش دیجیتال، نام کاربری معتبر تلگرام را وارد کنید؛ رمز یا کد ورود لازم نیست.');
            }
            $telegram = '@' . ltrim($telegram, '@');
        }
        $address = null;
        $shipping = null;
        if ($physical) {
            $source = $input['address'] ?? [];
            if (!is_array($source)) {
                app_error('آدرس گیرنده معتبر نیست.');
            }
            $address = ['province' => clean($source['province'] ?? '', 80), 'city' => clean($source['city'] ?? '', 80), 'line' => clean($source['line'] ?? '', 1000), 'postalCode' => digits($source['postalCode'] ?? '')];
            if ($address['province'] === '' || $address['city'] === '' || mb_strlen($address['line']) < 5 || !preg_match('/^[0-9]{10}$/D', $address['postalCode'])) {
                app_error('استان، شهر، آدرس کامل و کد پستی ۱۰ رقمی را وارد کنید.');
            }
            $shippingId = commerce_id($input['shippingId'] ?? null, 'روش ارسال');
            $rows = commerce_rows('SELECT * FROM shipping_methods WHERE id = ? AND enabled = 1 FOR UPDATE', [$shippingId]);
            if (!$rows) {
                app_error('یک روش ارسال فعال انتخاب کنید.');
            }
            $shipping = $rows[0];
        }
        // pay_on_delivery applies to carriage only: merchandise always requires gateway payment.
        $shippingFee = $shipping && !(int) $shipping['pay_on_delivery'] ? (int) $shipping['fee'] : 0;
        $totals = commerce_totals($lines, $shippingFee);
        if ($totals['total'] < 1000 && !payment_is_stub()) {
            app_error('مبلغ سفارش از حداقل قابل پرداخت درگاه کمتر است.');
        }
        $id = uid('order');
        $number = commerce_order_number('DZ');
        commerce_execute("INSERT INTO orders (id,number,type,user_id,customer_name,customer_phone,telegram_username,address_json,status,payment_status,subtotal,shipping_fee,total,shipping_id,shipping_name,pay_on_delivery,payment,tracking_code,stock_returned,reservation_id,created_at,updated_at) VALUES (?,?,'online',?,?,?,?,?,'awaiting_payment','unpaid',?,?,?,?,?,?,'zarinpal','',0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [
            $id, $number, $user['id'], $name, $phone, $telegram, $address === null ? null : json_value($address),
            $totals['subtotal'], $totals['shippingFee'], $totals['total'], $shipping['id'] ?? null,
            $shipping['name'] ?? 'تحویل دیجیتال', $shipping ? (int) $shipping['pay_on_delivery'] : 0, $reservation['id'],
        ]);
        commerce_insert_items($id, $lines);
        commerce_execute("UPDATE reservations SET status = 'payment_pending', order_id = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE id = ?", [$id, $reservation['id']]);
        audit('order.checkout', $id, ['number' => $number, 'total' => $totals['total']]);
        return ['id' => $id, 'number' => $number];
    });
    $redirect = payment_create($result['id']);
    return ['orderId' => $result['id'], 'number' => $result['number'], 'redirectUrl' => $redirect];
}

/** Resume the immutable, still-reserved order rather than recreating customer or price data. */
function commerce_resume(array $input): array
{
    $user = require_user();
    $id = commerce_id($input['id'] ?? null, 'سفارش');
    payment_require_configured();
    $order = transaction(function () use ($user, $id): array {
        $rows = commerce_rows('SELECT * FROM orders WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $user['id']]);
        if (!$rows) {
            app_error('سفارش پیدا نشد.', 404);
        }
        $order = $rows[0];
        if ($order['type'] !== 'online' || $order['status'] !== 'awaiting_payment' || $order['payment_status'] !== 'unpaid') {
            app_error('این سفارش در انتظار پرداخت نیست.');
        }
        $reservation = commerce_rows("SELECT id FROM reservations WHERE id = ? AND user_id = ? AND order_id = ? AND status = 'payment_pending' AND expires_at > UTC_TIMESTAMP()", [$order['reservation_id'], $user['id'], $id]);
        if (!$reservation) {
            app_error('مهلت رزرو این سفارش تمام شده است؛ موجودی و سبد خرید را بررسی و سفارش جدید ثبت کنید.', 409);
        }
        return $order;
    });
    return ['orderId' => $id, 'number' => $order['number'], 'redirectUrl' => payment_create($id)];
}

function commerce_pos(array $input): array
{
    $user = require_permission('pos.create');
    $source = $input['lines'] ?? null;
    if (!is_array($source) || !$source || count($source) > 50) {
        app_error('حداقل یک و حداکثر ۵۰ قلم به فروش حضوری اضافه کنید.');
    }
    $payment = $input['payment'] ?? '';
    if (!in_array($payment, ['cash', 'card'], true)) {
        app_error('روش پرداخت حضوری باید نقدی یا کارت‌خوان باشد.');
    }
    $aggregated = [];
    foreach ($source as $line) {
        if (!is_array($line)) {
            app_error('اقلام فروش معتبر نیستند.');
        }
        $pid = commerce_id($line['productId'] ?? null, 'محصول');
        $vid = commerce_id($line['variantId'] ?? null, 'گزینهٔ محصول');
        $quantity = int_value($line['quantity'] ?? null, 'تعداد', 1, 9999);
        if (isset($aggregated[$vid]) && $aggregated[$vid]['productId'] !== $pid) {
            app_error('گزینهٔ محصول معتبر نیست.');
        }
        $aggregated[$vid] = ['productId' => $pid, 'variantId' => $vid, 'quantity' => ($aggregated[$vid]['quantity'] ?? 0) + $quantity];
        if ($aggregated[$vid]['quantity'] > 9999) {
            app_error('تعداد فروش بیش از حد مجاز است.');
        }
    }
    $name = clean($input['customer'] ?? 'مشتری حضوری', 100);
    if ($name === '') {
        $name = 'مشتری حضوری';
    }
    return transaction(function () use ($user, $aggregated, $payment, $name): array {
        $variants = commerce_lock_variants(array_keys($aggregated));
        $lines = [];
        foreach ($aggregated as $vid => $line) {
            $lines[] = commerce_check_line($variants[$vid], $line['productId'], $line['quantity']);
        }
        $totals = commerce_totals($lines);
        $id = uid('pos');
        $number = commerce_order_number('POS');
        commerce_execute("INSERT INTO orders (id,number,type,user_id,customer_name,customer_phone,telegram_username,address_json,status,payment_status,subtotal,shipping_fee,total,shipping_id,shipping_name,pay_on_delivery,payment,tracking_code,stock_returned,reservation_id,created_at,updated_at) VALUES (?,?,'pos',?,?,NULL,NULL,NULL,'completed','paid',?,0,?,NULL,'تحویل حضوری',0,?,'',0,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$id, $number, $user['id'], $name, $totals['subtotal'], $totals['total'], $payment]);
        commerce_insert_items($id, $lines);
        foreach ($lines as $line) {
            if ($line['category'] !== 'digital') {
                commerce_execute('UPDATE product_variants SET stock = stock - ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$line['quantity'], $line['variantId']]);
                commerce_stock_movement($variants[$line['variantId']], -$line['quantity'], 'فروش حضوری ' . $number, $user, $id);
            }
        }
        audit('pos.sale', $id, ['number' => $number, 'total' => $totals['total']]);
        return ['id' => $id, 'number' => $number, 'status' => 'completed', 'total' => $totals['total']];
    });
}

function commerce_inventory_adjust(array $input): array
{
    $user = require_permission('inventory.edit');
    $pid = commerce_id($input['productId'] ?? null, 'محصول');
    $vid = commerce_id($input['variantId'] ?? null, 'گزینهٔ محصول');
    $delta = int_value($input['delta'] ?? null, 'تغییر موجودی', -99999, 99999);
    $reason = clean($input['reason'] ?? '', 300);
    if ($delta === 0 || $reason === '') {
        app_error('تغییر غیرصفر موجودی و دلیل آن را وارد کنید.');
    }
    return transaction(function () use ($user, $pid, $vid, $delta, $reason): array {
        $variant = commerce_lock_variants([$vid])[$vid];
        if ($variant['product_id'] !== $pid || $variant['category'] === 'digital') {
            app_error('این گزینه متعلق به کالای فیزیکی انتخاب‌شده نیست.');
        }
        $next = (int) $variant['stock'] + $delta;
        if ($next < 0 || $next > 1000000000 || $next < commerce_reserved($vid)) {
            app_error('موجودی نمی‌تواند از صفر یا تعداد رزروشده کمتر باشد.', 409);
        }
        commerce_execute('UPDATE product_variants SET stock = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$next, $vid]);
        commerce_stock_movement($variant, $delta, $reason, $user);
        audit('inventory.adjust', $vid, ['delta' => $delta, 'reason' => $reason]);
        return ['productId' => $pid, 'variantId' => $vid, 'stock' => $next];
    });
}

function commerce_order_lines(string $orderId): array
{
    return commerce_rows('SELECT * FROM order_items WHERE order_id = ? ORDER BY variant_id', [$orderId]);
}

function commerce_set_status(array $input): array
{
    $user = require_permission('orders.edit');
    $id = commerce_id($input['id'] ?? null, 'سفارش');
    $status = $input['status'] ?? '';
    if (!in_array($status, ['processing', 'shipped', 'completed', 'cancelled'], true)) {
        app_error('این وضعیت مجاز نیست؛ پرداخت فقط با تأیید درگاه ثبت می‌شود.');
    }
    return transaction(function () use ($id, $status, $user): array {
        $found = commerce_rows('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$found) {
            app_error('سفارش پیدا نشد.', 404);
        }
        $order = $found[0];
        if ($order['status'] === $status) {
            return ['id' => $id, 'status' => $status];
        }
        if ($order['status'] === 'cancelled') {
            app_error('سفارش لغوشده قابل فعال‌سازی مجدد نیست.');
        }
        $lines = commerce_order_lines($id);
        if ($status === 'cancelled') {
            // Shipped/completed goods require a physical return workflow rather than an automatic restock.
            if ($order['type'] !== 'pos' && in_array($order['status'], ['shipped', 'completed'], true)) {
                app_error('برای کالای تحویل‌شده ابتدا مرجوعی و سلامت آن را بررسی کنید؛ اصلاح انبار جداگانه ثبت می‌شود.');
            }
            $physical = array_values(array_filter($lines, fn($line) => $line['category'] !== 'digital'));
            $variants = commerce_lock_variants(array_column($physical, 'variant_id'));
            $deducted = $order['payment_status'] === 'paid' && $order['status'] !== 'payment_review';
            if ($deducted && !(int) $order['stock_returned']) {
                foreach ($physical as $line) {
                    commerce_execute('UPDATE product_variants SET stock = stock + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $line['quantity'], $line['variant_id']]);
                    commerce_stock_movement($variants[$line['variant_id']], (int) $line['quantity'], 'لغو فروش ' . $order['number'], $user, $id);
                }
            }
            if ($order['reservation_id']) {
                commerce_execute("UPDATE reservations SET status = 'released', expires_at = UTC_TIMESTAMP() WHERE id = ?", [$order['reservation_id']]);
            }
            $paymentStatus = in_array($order['payment_status'], ['paid', 'refund_pending'], true) ? 'refund_pending' : $order['payment_status'];
            commerce_execute("UPDATE orders SET status = 'cancelled', payment_status = ?, stock_returned = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$paymentStatus, $id]);
        } else {
            if ($order['payment_status'] !== 'paid' || $order['status'] === 'payment_review' || !in_array($order['status'], ['paid', 'processing', 'shipped'], true)) {
                app_error('این سفارش پرداخت تأییدشده و موجودی تخصیص‌یافته ندارد.');
            }
            if ($order['status'] === 'shipped' && $status !== 'completed') {
                app_error('سفارش ارسال‌شده فقط می‌تواند تکمیل شود.');
            }
            if ($status === 'shipped' && !array_filter($lines, fn($line) => $line['category'] !== 'digital')) {
                app_error('سفارش دیجیتال ارسال پستی ندارد؛ فعال‌سازی را بررسی و سپس تکمیل کنید.');
            }
            commerce_execute('UPDATE orders SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $id]);
        }
        audit('order.status', $id, ['from' => $order['status'], 'to' => $status]);
        return ['id' => $id, 'status' => $status];
    });
}

function commerce_tracking(array $input): array
{
    require_permission('orders.edit');
    $id = commerce_id($input['id'] ?? null, 'سفارش');
    $tracking = clean($input['trackingCode'] ?? '', 100);
    return transaction(function () use ($id, $tracking): array {
        $order = commerce_rows('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$order) {
            app_error('سفارش پیدا نشد.', 404);
        }
        commerce_execute('UPDATE orders SET tracking_code = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$tracking, $id]);
        audit('order.tracking', $id, ['trackingCode' => $tracking]);
        return ['id' => $id, 'trackingCode' => $tracking];
    });
}

function commerce_action(string $action, array $input): mixed
{
    return match ($action) {
        'cart.add' => commerce_cart_update($input, true),
        'cart.set' => commerce_cart_update($input, false),
        'reservation.create' => commerce_reserve(),
        'reservation.release' => commerce_release(),
        'order.checkout' => commerce_checkout($input),
        'order.resume' => commerce_resume($input),
        'pos.sale' => commerce_pos($input),
        'inventory.adjust' => commerce_inventory_adjust($input),
        'order.status' => commerce_set_status($input),
        'order.tracking' => commerce_tracking($input),
        default => app_error('عملیات فروش پشتیبانی نمی‌شود.', 404),
    };
}
