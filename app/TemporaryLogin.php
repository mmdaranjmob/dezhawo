<?php
declare(strict_types=1);

/** An explicit, host-configured owner recovery login while SMS is unavailable. */
function temporary_login_configuration(): ?array
{
    $settings = config()['temporary_login'] ?? null;
    if (!is_array($settings) || ($settings['enabled'] ?? false) !== true) {
        return null;
    }
    $phone = $settings['phone'] ?? null;
    $hash = $settings['password_hash'] ?? null;
    if (!is_string($phone) || !preg_match('/^09[0-9]{9}$/D', $phone)
        || !is_string($hash) || (password_get_info($hash)['algo'] ?? null) === null) {
        return null;
    }
    return ['phone' => $phone, 'password_hash' => $hash];
}

function temporary_login_enabled(): bool
{
    return temporary_login_configuration() !== null;
}

function temporary_login_session_stamp(array $user): string
{
    $settings = temporary_login_configuration();
    if ($settings === null || !hash_equals($settings['phone'], (string)$user['phone'])) {
        app_error('ورود موقت برای این حساب فعال نیست.', 401);
    }
    // Changing the configured phone or hash revokes every temporary session.
    return hash_hmac('sha256', 'temporary|' . auth_session_stamp($user) . '|'
        . $settings['phone'] . '|' . $settings['password_hash'], auth_key());
}

function temporary_login_session_valid(array $user): bool
{
    $settings = temporary_login_configuration();
    $stamp = $_SESSION['auth_stamp'] ?? null;
    $issued = $_SESSION['auth_issued_at'] ?? null;
    if ($settings === null || !hash_equals($settings['phone'], (string)$user['phone'])
        || !is_string($stamp) || !is_int($issued) || $issued > time()
        || time() - $issued > 8 * 3600) {
        return false;
    }
    return hash_equals(temporary_login_session_stamp($user), $stamp);
}

function auth_temporary_login(array $input): array
{
    $settings = temporary_login_configuration();
    if ($settings === null) {
        app_error('ورود موقت فعال نیست؛ از کد تأیید پیامکی استفاده کنید.', 403);
    }
    $phone = auth_phone($input);
    $raw = $input['password'] ?? '';
    $password = (is_string($raw) || is_int($raw)) ? digits($raw) : '';
    auth_rate_limit([
        ['temporary:login:ip:' . auth_request_ip(), 20, 900],
        ['temporary:login:phone:' . $phone, 5, 900],
    ]);
    // Always verify the hash, even for another phone; never accept this as an OTP.
    $matches = strlen($password) <= 128 && password_verify($password, $settings['password_hash']);
    if (!$matches || !hash_equals($settings['phone'], $phone)) {
        app_error('شماره موبایل یا رمز ورود موقت صحیح نیست.', 401);
    }
    $userId = transaction(function () use ($phone): ?string {
        // A permanent marker prevents later logins from undoing role changes.
        // Locking the owner role serializes first-time provisioning and installer.
        $role = db()->query("SELECT id FROM roles WHERE id = 'owner' FOR UPDATE")->fetchColumn();
        if ($role === false) {
            app_error('ابتدا جدول‌های فروشگاه را در دیتابیس وارد کنید.', 503);
        }
        $q = db()->prepare("SELECT value FROM settings WHERE name = 'temporary_owner_provisioned' FOR UPDATE");
        $q->execute();
        $provisioned = $q->fetchColumn() !== false;
        $q = db()->prepare('SELECT id, enabled FROM users WHERE phone = ? FOR UPDATE');
        $q->execute([$phone]);
        $user = $q->fetch(PDO::FETCH_ASSOC);
        if ($user && (int)$user['enabled'] !== 1) {
            return null;
        }
        if (!$provisioned) {
            if (!$user) {
                $id = uid('user');
                $q = db()->prepare("INSERT INTO users (id, name, phone, password_hash, role_id, enabled, created_at, updated_at) VALUES (?, ?, ?, NULL, 'owner', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                $q->execute([$id, 'مدیر فروشگاه', $phone]);
                $user = ['id' => $id, 'enabled' => 1];
            } else {
                $q = db()->prepare("UPDATE users SET role_id = 'owner', updated_at = UTC_TIMESTAMP() WHERE id = ?");
                $q->execute([$user['id']]);
            }
            $q = db()->prepare("INSERT INTO settings (name, value, updated_at) VALUES ('temporary_owner_provisioned', 'true', UTC_TIMESTAMP()), ('installation_complete', 'true', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value = 'true', updated_at = UTC_TIMESTAMP()");
            $q->execute();
        }
        return $user ? (string)$user['id'] : null;
    });
    if ($userId === null) {
        app_error('شماره موبایل یا رمز ورود موقت صحیح نیست.', 401);
    }
    auth_begin_session($userId, 'temporary');
    audit('auth.temporary_login', $userId);
    return ['user' => require_user()];
}
