<?php
declare(strict_types=1);

/** Session authentication and DB-backed permissions. Never serialize password hashes. */
function current_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    $kind = $_SESSION['auth_kind'] ?? null;
    if (!is_string($id) || !in_array($kind, ['staff', 'customer'], true)) {
        if (is_string($id)) {
            unset($_SESSION['user_id'], $_SESSION['auth_kind'], $_SESSION['auth_stamp'], $_SESSION['cart_key']);
        }
        return null;
    }
    $q = db()->prepare('SELECT id, name, phone, role_id, enabled, password_hash FROM users WHERE id = ? LIMIT 1');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $valid = $row && (int)$row['enabled'] === 1;
    if ($valid && $kind === 'customer') {
        // Promoting a customer must require a subsequent password login.
        $valid = $row['role_id'] === 'customer' && empty($row['password_hash']);
    } elseif ($valid && $kind === 'staff') {
        $valid = is_string($row['password_hash']) && $row['password_hash'] !== '' && $row['role_id'] !== 'customer';
        $stamp = $_SESSION['auth_stamp'] ?? null;
        if ($valid) {
            $valid = is_string($stamp) && hash_equals(
                hash_hmac('sha256', $row['id'] . '|' . $row['password_hash'], auth_key()),
                $stamp
            );
        }
    }
    if (!$valid) {
        unset($_SESSION['user_id'], $_SESSION['auth_kind'], $_SESSION['auth_stamp'], $_SESSION['cart_key']);
        return null;
    }
    $grants = [];
    if ($kind === 'staff') {
        $q = db()->prepare('SELECT permission FROM role_permissions WHERE role_id = ? ORDER BY permission');
        $q->execute([$row['role_id']]);
        $grants = array_values(array_intersect($q->fetchAll(PDO::FETCH_COLUMN), permissions()));
    }
    return [
        'id' => $row['id'], 'name' => $row['name'], 'phone' => $row['phone'],
        'roleId' => $row['role_id'], 'role_id' => $row['role_id'],
        'enabled' => true, 'permissions' => $grants,
    ];
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        app_error('برای ادامه وارد حساب خود شوید.', 401);
    }
    return $user;
}

function can(string $permission, ?array $user = null): bool
{
    if (!in_array($permission, permissions(), true)) {
        return false;
    }
    $user ??= current_user();
    return $user !== null && in_array($permission, $user['permissions'] ?? [], true);
}

function require_permission(string $permission): array
{
    $user = require_user();
    if (!can($permission, $user)) {
        app_error('اجازهٔ انجام این عملیات را ندارید.', 403);
    }
    return $user;
}

function auth_key(): string
{
    $key = config()['app']['key'] ?? '';
    if (!is_string($key) || strlen($key) < 32 || str_contains(strtoupper($key), 'CHANGE_')) {
        app_error('کلید امنیتی فروشگاه در تنظیمات سرور کامل نشده است.', 503);
    }
    return $key;
}

function auth_phone(array $input): string
{
    $value = $input['phone'] ?? '';
    if (!is_string($value) && !is_int($value)) {
        app_error('شماره موبایل معتبر وارد کنید.');
    }
    $phone = phone_value($value);
    if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
        app_error('شماره موبایل معتبر وارد کنید.');
    }
    return $phone;
}

/** All buckets are updated together, then errors are raised after the commit. */
function auth_rate_limit(array $limits): void
{
    $key = auth_key();
    $buckets = [];
    foreach ($limits as $limit) {
        $bucket = hash_hmac('sha256', (string)$limit[0], $key);
        $buckets[$bucket] = ['maximum' => (int)$limit[1], 'seconds' => (int)$limit[2]];
    }
    ksort($buckets, SORT_STRING);
    $allowed = transaction(function () use ($buckets): bool {
        $connection = db();
        $insert = $connection->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, UTC_TIMESTAMP(), 0) ON DUPLICATE KEY UPDATE bucket = VALUES(bucket)');
        $read = $connection->prepare('SELECT hits, TIMESTAMPDIFF(SECOND, window_start, UTC_TIMESTAMP()) AS age_seconds FROM rate_limits WHERE bucket = ? FOR UPDATE');
        $reset = $connection->prepare('UPDATE rate_limits SET hits = 0, window_start = UTC_TIMESTAMP() WHERE bucket = ?');
        $increment = $connection->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?');
        $accepted = true;
        foreach ($buckets as $bucket => $limit) {
            $insert->execute([$bucket]);
            $read->execute([$bucket]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            $hits = (int)$row['hits'];
            if ((int)$row['age_seconds'] >= $limit['seconds'] || (int)$row['age_seconds'] < 0) {
                $reset->execute([$bucket]);
                $hits = 0;
            }
            if ($hits >= $limit['maximum']) {
                $accepted = false;
            }
            // Saturation prevents integer overflow under prolonged hostile traffic.
            if ($hits < 1000000) {
                $increment->execute([$bucket]);
            }
        }
        return $accepted;
    });
    if (!$allowed) {
        app_error('تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 429);
    }
}

function auth_request_ip(): string
{
    // Forwarded headers are intentionally ignored; a client must not choose a bucket.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function auth_otp_hash(string $challengeId, string $phone, string $code): string
{
    return hash_hmac('sha256', $challengeId . '|' . $phone . '|' . $code, auth_key());
}

function auth_request_otp(array $input): array
{
    $phone = auth_phone($input);
    if (!sms_configured()) {
        app_error('سرویس پیامک فروشگاه هنوز تنظیم نشده است؛ با پشتیبانی تماس بگیرید.', 503);
    }
    auth_rate_limit([
        ['otp:request:ip:' . auth_request_ip(), 20, 3600],
        ['otp:request:phone:' . $phone, 5, 3600],
        ['otp:request:cooldown:' . $phone, 1, 60],
    ]);
    $challengeId = uid('otp');
    $code = (string)random_int(100000, 999999);
    $hash = auth_otp_hash($challengeId, $phone, $code);
    $created = transaction(function () use ($phone, $challengeId, $hash): bool {
        $q = db()->prepare('SELECT role_id, password_hash, enabled FROM users WHERE phone = ? FOR UPDATE');
        $q->execute([$phone]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        if ($user && ((int)$user['enabled'] !== 1 || $user['role_id'] !== 'customer' || !empty($user['password_hash']))) {
            return false;
        }
        $q = db()->prepare('UPDATE otp_challenges SET consumed_at = UTC_TIMESTAMP() WHERE phone = ? AND purpose = ? AND consumed_at IS NULL');
        $q->execute([$phone, 'customer']);
        $q = db()->prepare('INSERT INTO otp_challenges (id, phone, purpose, code_hash, attempts, expires_at, consumed_at, created_at) VALUES (?, ?, ?, ?, 0, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), NULL, UTC_TIMESTAMP())');
        $q->execute([$challengeId, $phone, 'customer', $hash]);
        return true;
    });
    if ($created) {
        try {
            sms_send_otp($phone, $code, $challengeId);
        } catch (Throwable $error) {
            // A failed provider call must leave no usable challenge behind.
            $q = db()->prepare('UPDATE otp_challenges SET consumed_at = UTC_TIMESTAMP() WHERE id = ?');
            $q->execute([$challengeId]);
            throw $error;
        }
    }
    // The same response protects staff/disabled phone numbers from enumeration.
    return ['message' => 'اگر شماره واجد شرایط ورود مشتری باشد، رمز پیامکی ارسال می‌شود.', 'expiresIn' => 300, 'retryAfter' => 60];
}

function auth_verify_otp(array $input): array
{
    $phone = auth_phone($input);
    $raw = $input['code'] ?? '';
    $code = (is_string($raw) || is_int($raw)) ? trim(digits($raw)) : '';
    auth_rate_limit([
        ['otp:verify:ip:' . auth_request_ip(), 60, 900],
        ['otp:verify:phone:' . $phone, 15, 900],
    ]);
    $userId = transaction(function () use ($phone, $code): ?string {
        $q = db()->prepare('SELECT id, code_hash, attempts, consumed_at, (expires_at > UTC_TIMESTAMP()) AS unexpired FROM otp_challenges WHERE phone = ? AND purpose = ? ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE');
        $q->execute([$phone, 'customer']);
        $challenge = $q->fetch(PDO::FETCH_ASSOC);
        $id = $challenge ? (string)$challenge['id'] : 'missing';
        $candidate = auth_otp_hash($id, $phone, $code);
        $matches = hash_equals($challenge ? (string)$challenge['code_hash'] : str_repeat('0', 64), $candidate);
        if (!$challenge || $challenge['consumed_at'] !== null || (int)$challenge['unexpired'] !== 1 || (int)$challenge['attempts'] >= 5) {
            return null;
        }
        if (!$matches || !preg_match('/^[0-9]{6}$/D', $code)) {
            $attempts = (int)$challenge['attempts'] + 1;
            $q = db()->prepare('UPDATE otp_challenges SET attempts = ?, consumed_at = CASE WHEN ? >= 5 THEN UTC_TIMESTAMP() ELSE consumed_at END WHERE id = ?');
            $q->execute([$attempts, $attempts, $id]);
            return null;
        }
        $q = db()->prepare('SELECT id, role_id, enabled, password_hash FROM users WHERE phone = ? FOR UPDATE');
        $q->execute([$phone]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        $q = db()->prepare('UPDATE otp_challenges SET consumed_at = UTC_TIMESTAMP() WHERE id = ?');
        $q->execute([$id]);
        if ($user && ((int)$user['enabled'] !== 1 || $user['role_id'] !== 'customer' || !empty($user['password_hash']))) {
            return null;
        }
        if (!$user) {
            $newId = uid('user');
            $q = db()->prepare('INSERT INTO users (id, name, phone, password_hash, role_id, enabled, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $q->execute([$newId, 'مشتری', $phone, 'customer']);
            return $newId;
        }
        return (string)$user['id'];
    });
    if ($userId === null) {
        app_error('رمز نامعتبر یا منقضی شده است؛ رمز جدید درخواست کنید.', 401);
    }
    auth_begin_session($userId, 'customer');
    audit('auth.customer_login', $userId);
    return ['user' => require_user()];
}

function auth_admin_login(array $input): array
{
    $phone = auth_phone($input);
    auth_rate_limit([
        ['staff:login:ip:' . auth_request_ip(), 30, 900],
        ['staff:login:phone:' . $phone, 10, 900],
    ]);
    $password = $input['password'] ?? '';
    $password = is_string($password) && strlen($password) <= 72 ? $password : '';
    $q = db()->prepare('SELECT id, password_hash, enabled, role_id FROM users WHERE phone = ? LIMIT 1');
    $q->execute([$phone]);
    $user = $q->fetch(PDO::FETCH_ASSOC);
    // A valid bcrypt hash prevents a missing number from skipping expensive verification.
    $dummyHash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $storedHash = $user && is_string($user['password_hash']) && $user['password_hash'] !== '' ? $user['password_hash'] : $dummyHash;
    $verified = password_verify($password, $storedHash);
    if (!$user || !$verified || (int)$user['enabled'] !== 1 || empty($user['password_hash']) || $user['role_id'] === 'customer') {
        app_error('شماره موبایل یا رمز ورود صحیح نیست.', 401);
    }
    if (password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $q = db()->prepare('UPDATE users SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND password_hash = ?');
        $q->execute([$newHash, $user['id'], $storedHash]);
        $storedHash = $newHash;
    }
    auth_begin_session((string)$user['id'], 'staff', $storedHash);
    audit('auth.staff_login', (string)$user['id']);
    return ['user' => require_user()];
}

function auth_begin_session(string $userId, string $kind, ?string $passwordHash = null): void
{
    $stamp = $kind === 'staff' && $passwordHash !== null
        ? hash_hmac('sha256', $userId . '|' . $passwordHash, auth_key()) : null;
    if (!session_regenerate_id(true)) {
        app_error('ایجاد نشست ورود ممکن نشد؛ دوباره تلاش کنید.', 503);
    }
    if (is_string($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] !== $userId) {
        unset($_SESSION['cart_key']);
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['auth_kind'] = $kind;
    if ($stamp !== null) {
        $_SESSION['auth_stamp'] = $stamp;
    } else {
        unset($_SESSION['auth_stamp']);
    }
    unset($_SESSION['csrf']);
}

function auth_logout(): array
{
    unset($_SESSION['user_id'], $_SESSION['auth_kind'], $_SESSION['auth_stamp'], $_SESSION['csrf'], $_SESSION['cart_key']);
    // A subsequent browser account must not inherit private pending reservations.
    if (!session_regenerate_id(true)) {
        app_error('خروج از نشست کامل نشد؛ صفحه را دوباره باز کنید.', 503);
    }
    return ['loggedOut' => true];
}

function auth_action(string $action, array $input): mixed
{
    return match ($action) {
        'auth.requestOtp' => auth_request_otp($input),
        'auth.verifyOtp' => auth_verify_otp($input),
        'auth.adminLogin' => auth_admin_login($input),
        'auth.logout' => auth_logout(),
        default => app_error('عملیات ورود شناخته نشد.', 404),
    };
}
