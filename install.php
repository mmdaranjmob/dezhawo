<?php
declare(strict_types=1);

// One-time first-owner creation. Import database/schema.sql before visiting.
// No credentials are printed, prefilled, logged, or saved outside the database.
final class InstallationValidation extends RuntimeException {}

function installation_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installation_is_complete(PDO $pdo): bool
{
    // A disabled owner still proves installation; removing the marker cannot reset it.
    if ($pdo->query("SELECT id FROM users WHERE role_id = 'owner' LIMIT 1")->fetchColumn() !== false) {
        return true;
    }
    $marker = $pdo->query("SELECT value FROM settings WHERE name = 'installation_complete'")->fetchColumn();
    return $marker !== false && json_decode((string) $marker, true) === true;
}

$ready = false;
$installed = false;
$success = false;
$message = '';
$status = 200;
$safeName = '';
$safePhone = '';
$csrf = '';

try {
    $required = ['pdo_mysql', 'curl', 'mbstring'];
    if (PHP_VERSION_ID < 80200 || array_filter($required, static fn(string $extension): bool => !extension_loaded($extension))) {
        throw new InstallationValidation('در cPanel نسخهٔ PHP را روی ۸٫۲ یا بالاتر بگذارید و افزونه‌های pdo_mysql، curl و mbstring را فعال کنید.');
    }
    require __DIR__ . '/app/bootstrap.php';
    $configuration = config();
    $configuredInstallationKey = $configuration['app']['installation_key'] ?? null;
    if (!is_string($configuredInstallationKey) || strlen($configuredInstallationKey) < 32
        || str_contains(strtoupper($configuredInstallationKey), 'CHANGE')
        || str_contains(strtoupper($configuredInstallationKey), 'YOUR_')
        || hash_equals((string) $configuration['app']['key'], $configuredInstallationKey)) {
        throw new InstallationValidation('یک کلید نصب تصادفی و مستقل از کلید برنامه در app/config.php تنظیم کنید.');
    }
    if (($configuration['app']['env'] ?? 'production') === 'production') {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        if (!$isHttps) {
            throw new InstallationValidation('ابتدا گواهی SSL دامنه را فعال کنید و این صفحه را با HTTPS باز کنید.');
        }
    }
    $pdo = db();
    // Check schema before displaying a form that could never succeed.
    $ownerRole = $pdo->query("SELECT id FROM roles WHERE id = 'owner'")->fetchColumn();
    if ($ownerRole === false) {
        throw new InstallationValidation('ابتدا فایل database/schema.sql را در دیتابیس خالیِ همین فروشگاه با phpMyAdmin وارد کنید.');
    }
    $installed = installation_is_complete($pdo);
    if ($installed) {
        $status = 409;
        $message = 'نصب قبلاً انجام شده است. ساخت مجدد مدیر از این صفحه امکان‌پذیر نیست.';
    } else {
        $ready = true;
        $csrf = csrf_token();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $postedToken = $_POST['csrf'] ?? '';
            if (!is_string($postedToken) || !hash_equals($csrf, $postedToken)) {
                $status = 403;
                throw new InstallationValidation('اعتبار فرم منقضی یا نامعتبر است. صفحه را تازه کنید و دوباره تلاش کنید.');
            }
            $installationKey = $_POST['installation_key'] ?? '';
            $expectedKey = $configuredInstallationKey;
            if (!is_string($installationKey) || !hash_equals($expectedKey, $installationKey)) {
                $status = 403;
                throw new InstallationValidation('کلید نصب صحیح نیست؛ کلیدی را وارد کنید که در app/config.php تنظیم کرده‌اید.');
            }
            foreach (['name', 'phone', 'password', 'password_confirmation'] as $field) {
                if (!isset($_POST[$field]) || !is_string($_POST[$field])) {
                    throw new InstallationValidation('تمام فیلدهای ساخت مدیر را تکمیل کنید.');
                }
            }
            $safeName = trim($_POST['name']);
            $safePhone = digits(trim($_POST['phone']));
            if (!mb_check_encoding($safeName, 'UTF-8') || mb_strlen($safeName) < 2 || mb_strlen($safeName) > 100
                || preg_match('/[\x00-\x1F\x7F]/u', $safeName)) {
                throw new InstallationValidation('نام مدیر باید بین ۲ تا ۱۰۰ نویسه و بدون نویسهٔ کنترلی باشد.');
            }
            if (!preg_match('/^09[0-9]{9}$/D', $safePhone)) {
                throw new InstallationValidation('شماره موبایل ایران را به شکل ۰۹ و در مجموع ۱۱ رقم وارد کنید.');
            }
            $password = $_POST['password'];
            if (!hash_equals($password, $_POST['password_confirmation'])) {
                throw new InstallationValidation('رمز عبور و تکرار آن یکسان نیستند.');
            }
            if (strlen($password) < 12 || strlen($password) > 72
                || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
                || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)
                || preg_match('/[\x00-\x1F\x7F]/', $password)) {
                throw new InstallationValidation('رمز باید ۱۲ تا ۷۲ بایت و شامل حرف انگلیسی بزرگ، حرف کوچک، عدد و علامت باشد.');
            }
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            unset($password, $_POST['password'], $_POST['password_confirmation'], $_POST['installation_key']);
            if ($passwordHash === false) {
                throw new InstallationValidation('ساخت رمز عبور امکان‌پذیر نشد؛ دوباره تلاش کنید.');
            }
            $pdo->beginTransaction();
            try {
                // Serialize simultaneous installation requests around a permanent seed row.
                $lockedOwner = $pdo->query("SELECT id FROM roles WHERE id = 'owner' FOR UPDATE")->fetchColumn();
                if ($lockedOwner === false) {
                    throw new InstallationValidation('نقش مدیر اصلی در دیتابیس وجود ندارد؛ واردکردن ساختار دیتابیس را بررسی کنید.');
                }
                if (installation_is_complete($pdo)) {
                    $status = 409;
                    $installed = true;
                    $ready = false;
                    throw new InstallationValidation('نصب قبلاً انجام شده است. ساخت مدیر دوم از این صفحه مسدود است.');
                }
                $ownerId = uid('usr');
                $insert = $pdo->prepare('INSERT INTO users (id, name, phone, password_hash, role_id, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
                $insert->execute([$ownerId, $safeName, $safePhone, $passwordHash, 'owner']);
                $lock = $pdo->prepare("INSERT INTO settings (name, value, updated_at) VALUES ('installation_complete', 'true', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value = 'true', updated_at = UTC_TIMESTAMP()");
                $lock->execute();
                $log = $pdo->prepare('INSERT INTO audit_logs (id, user_id, user_name, action, target_id, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
                $log->execute([uid('audit'), $ownerId, $safeName, 'installation.complete', $ownerId, '{"first_owner":true}']);
                $pdo->commit();
                $success = true;
                $ready = false;
                $status = 201;
                $message = 'مدیر اصلی ساخته شد و نصب قفل شد. اکنون از صفحهٔ فروشگاه وارد پنل مدیریت شوید.';
                session_regenerate_id(true);
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $failure;
            }
        }
    }
} catch (InstallationValidation $failure) {
    $message = $failure->getMessage();
    if ($status === 200) {
        $status = $ready ? 400 : 503;
    }
} catch (Throwable $failure) {
    // Deliberately exclude exception messages, SQL, paths and secrets from the response/log.
    $message = 'راه‌اندازی هنوز آماده نیست. app/config.php، اتصال دیتابیس و واردکردن database/schema.sql را بررسی کنید. اطلاعات محرمانه را در گفتگو نفرستید.';
    $status = 503;
    error_log('DEZHAWO installation failed (' . get_class($failure) . ').');
}

http_response_code($status);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#f5f4ef">
  <script src="assets/theme.js"></script>
  <title>راه‌اندازی دژاوو | DEZHAWO</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f5f3eb;color:#272821;font:15px/1.9 Tahoma,Arial,sans-serif}main{width:min(640px,calc(100% - 32px));margin:48px auto;padding:30px;background:#fffdf7;border:1px solid #e3dfd2;border-radius:22px}h1{font-size:24px;margin:0 0 10px}h2{font-size:17px}p{margin:12px 0}label{display:block;margin:18px 0 6px}input{width:100%;border:1px solid #ccc8bc;border-radius:10px;padding:13px;background:#fff;font:inherit;min-width:0}input:focus{outline:2px solid #858d5d}button,a.button{display:inline-block;margin-top:24px;border:0;border-radius:10px;padding:13px 24px;background:#282b22;color:#fff;text-decoration:none;font:inherit;cursor:pointer}.message{padding:14px;border:1px solid #e1b5a0;border-radius:12px;background:#fff0e7}.success{border-color:#bdcba4;background:#eef4df}small{color:#666}code{direction:ltr;display:inline-block;overflow-wrap:anywhere}ol{padding-right:22px}li{margin:8px 0}
  </style>
  <link rel="stylesheet" href="assets/theme.css">
</head>
<body class="theme-page theme-install"><main>
  <div class="theme-page-header"><h1>راه‌اندازی دژاوو <span dir="ltr">DEZHAWO</span></h1><?php require __DIR__ . '/app/theme-control.php'; ?></div>
  <?php if ($message !== ''): ?>
    <p class="message <?= $success ? 'success' : '' ?>" role="status"><?= installation_escape($message) ?></p>
  <?php endif; ?>
  <?php if ($success || $installed): ?>
    <p>این صفحه دوباره مدیر نمی‌سازد، حتی اگر فایل تنظیمات تغییر کند. پس از نصب، فایل <code>install.php</code> را از هاست حذف کنید.</p>
    <a class="button" href="./">ورود به فروشگاه</a>
  <?php elseif ($ready): ?>
    <p>ساخت مدیر اصلی فقط یک‌بار انجام می‌شود. این ورود با شماره موبایل و رمز عبور است؛ ورود مشتریان با پیامک جداگانه تنظیم می‌شود.</p>
    <form method="post" action="install.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= installation_escape($csrf) ?>">
      <label for="installation-key">کلید نصب از تنظیمات خصوصی هاست</label>
      <input id="installation-key" name="installation_key" type="password" required maxlength="256" autocomplete="off" dir="ltr">
      <label for="owner-name">نام مدیر اصلی</label>
      <input id="owner-name" name="name" value="<?= installation_escape($safeName) ?>" required minlength="2" maxlength="100" autocomplete="name">
      <label for="owner-phone">شماره موبایل مدیر</label>
      <input id="owner-phone" name="phone" value="<?= installation_escape($safePhone) ?>" type="tel" required maxlength="11" inputmode="numeric" placeholder="09xxxxxxxxx" dir="ltr" autocomplete="tel">
      <label for="owner-password">رمز عبور مدیر</label>
      <input id="owner-password" name="password" type="password" required minlength="12" maxlength="72" dir="ltr" autocomplete="new-password" aria-describedby="password-help">
      <small id="password-help">۱۲ تا ۷۲ بایت؛ شامل حروف انگلیسی بزرگ و کوچک، عدد و علامت.</small>
      <label for="owner-confirmation">تکرار رمز عبور</label>
      <input id="owner-confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="72" dir="ltr" autocomplete="new-password">
      <button type="submit">ساخت مدیر و تکمیل نصب</button>
    </form>
  <?php else: ?>
    <h2>آماده‌سازی در cPanel</h2>
    <ol>
      <li>PHP ۸٫۲ یا بالاتر و افزونه‌های <code>pdo_mysql</code>، <code>curl</code> و <code>mbstring</code> را فعال و گواهی SSL را نصب کنید.</li>
      <li>یک دیتابیس خالی و کاربر اختصاصی بسازید و کاربر را با دسترسی کامل به همان دیتابیس متصل کنید.</li>
      <li>فایل <code>database/schema.sql</code> را با phpMyAdmin در دیتابیس جدید وارد کنید.</li>
      <li>از <code>app/config.example.php</code> یک کپی با نام <code>app/config.php</code> بسازید؛ اطلاعات دیتابیس، آدرس HTTPS و دو کلید تصادفی مستقل را تنظیم کنید.</li>
      <li>این صفحه را با HTTPS دوباره باز کنید. راهنمای کامل در فایل <code>README.md</code> داخل پروژه است.</li>
    </ol>
    <p>کلیدها و رمز دیتابیس فقط روی هاست نگهداری شوند. این نصب هیچ نام کاربری یا رمز پیش‌فرضی ندارد.</p>
  <?php endif; ?>
</main></body></html>
