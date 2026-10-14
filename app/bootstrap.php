<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** Shared hosting entry helpers. No dependency manager or framework required. */
final class AppError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function app_error(string $message, int $http = 400): never
{
    throw new AppError($message, $http);
}

function config_path(): string
{
    $override = getenv('DEZHAWO_CONFIG');
    return is_string($override) && $override !== '' ? $override : __DIR__ . '/config.php';
}

function config(): array
{
    static $loaded;
    if (is_array($loaded)) {
        return $loaded;
    }
    $path = config_path();
    if (!is_file($path)) {
        app_error('تنظیمات هاست هنوز ثبت نشده است. فایل app/config.example.php را طبق راهنمای نصب به app/config.php کپی و تکمیل کنید.', 503);
    }
    $value = require $path;
    if (!is_array($value)) {
        app_error('ساختار فایل تنظیمات معتبر نیست.', 503);
    }
    $key = (string)($value['app']['key'] ?? '');
    if (strlen($key) < 32 || str_contains(strtoupper($key), 'CHANGE') || str_contains(strtoupper($key), 'YOUR_')) {
        app_error('کلید امنیتی تصادفی برنامه در فایل تنظیمات ثبت نشده است.', 503);
    }
    if (!in_array($value['app']['env'] ?? 'production', ['production', 'development', 'testing'], true)) {
        app_error('نوع محیط در تنظیمات معتبر نیست.', 503);
    }
    foreach (['host', 'name', 'user'] as $name) {
        if (!is_string($value['database'][$name] ?? null) || $value['database'][$name] === '') {
            app_error('تنظیمات اتصال دیتابیس کامل نیست.', 503);
        }
    }
    $loaded = $value;
    return $loaded;
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }
    if (!extension_loaded('pdo_mysql')) {
        app_error('افزونهٔ pdo_mysql باید در تنظیمات PHP هاست فعال شود.', 503);
    }
    $values = config()['database'];
    $host = (string)$values['host'];
    $name = (string)$values['name'];
    $port = int_value($values['port'] ?? 3306, 'پورت دیتابیس', 1, 65535);
    if (str_contains($host, ';') || str_contains($name, ';')) {
        app_error('آدرس دیتابیس در تنظیمات معتبر نیست.', 503);
    }
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $connection = new PDO($dsn, (string)$values['user'], (string)($values['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $connection->exec("SET time_zone = '+00:00'");
    $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return $connection;
}

function permissions(): array
{
    return ['products.view', 'products.edit', 'products.delete', 'inventory.view', 'inventory.edit',
        'pos.create', 'orders.view', 'orders.edit', 'finance.view', 'tickets.view', 'tickets.reply',
        'shipping.edit', 'users.edit', 'roles.edit', 'appearance.edit', 'enamad.edit', 'settings.edit'];
}

function clean(mixed $value, int $max = 300): string
{
    if (is_array($value) || is_object($value)) {
        app_error('نوع دادهٔ ورودی معتبر نیست.');
    }
    $text = trim((string)($value ?? ''));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
    if ($text === null) {
        app_error('متن ورودی باید UTF-8 معتبر باشد.');
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}

function digits(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        app_error('عدد ورودی معتبر نیست.');
    }
    return strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}

function phone_value(mixed $value): string
{
    $phone = preg_replace('/[\s\-()]/', '', digits(clean($value, 30))) ?? '';
    if (str_starts_with($phone, '+98')) {
        $phone = '0' . substr($phone, 3);
    } elseif (str_starts_with($phone, '0098')) {
        $phone = '0' . substr($phone, 4);
    }
    if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
        app_error('شماره موبایل معتبر ایرانی وارد کنید.');
    }
    return $phone;
}

function int_value(mixed $value, string $label, int $min = 0, int $max = 1000000000000): int
{
    if (is_bool($value) || $value === null || is_array($value) || is_object($value)) {
        app_error($label . ' معتبر نیست.');
    }
    $text = digits($value);
    if (!preg_match('/^-?[0-9]+$/D', $text) || strlen(ltrim($text, '-0')) > 13) {
        app_error($label . ' باید عدد صحیح باشد.');
    }
    $number = (int)$text;
    if ($number < $min || $number > $max) {
        app_error($label . ' خارج از محدودهٔ مجاز است.');
    }
    return $number;
}

function uid(string $prefix): string
{
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix) ?? 'id', 0, 25) . '_' . bin2hex(random_bytes(16));
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function iso_time(?string $date): ?string
{
    if (!$date) {
        return null;
    }
    try {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) {
        return null;
    }
}

function json_value(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function transaction(callable $callback): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retryable = $error instanceof PDOException &&
                ((string)$error->getCode() === '40001' || in_array((int)($error->errorInfo[1] ?? 0), [1205, 1213], true));
            if (!$retryable || $attempt === 2) {
                throw $error;
            }
            usleep(random_int(20000, 70000));
        }
    }
    app_error('ثبت تغییرات موقتاً ممکن نیست؛ دوباره تلاش کنید.', 503);
}

function csrf_token(): string
{
    if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) !== 64) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function cart_key(): string
{
    if (!is_string($_SESSION['cart_key'] ?? null) || strlen($_SESSION['cart_key']) !== 64) {
        $_SESSION['cart_key'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cart_key'];
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        app_error('این عملیات فقط با درخواست POST مجاز است.', 405);
    }
    if ((config()['app']['env'] ?? 'production') === 'production' && !request_is_https()) {
        app_error('برای ورود و ثبت تغییرات از آدرس HTTPS فروشگاه استفاده کنید.', 426);
    }
}

function check_csrf(): void
{
    $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($supplied) || strlen($supplied) !== 64 || !hash_equals(csrf_token(), $supplied)) {
        app_error('نشست شما معتبر نیست؛ صفحه را دوباره بارگذاری کنید.', 419);
    }
}

function body(): array
{
    static $body;
    if (is_array($body)) {
        return $body;
    }
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 1048576) {
        app_error('حجم درخواست بیش از حد مجاز است.', 413);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        app_error('درخواست باید با قالب JSON ارسال شود.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
        app_error('حجم درخواست بیش از حد مجاز است.', 413);
    }
    try {
        $parsed = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        app_error('قالب JSON درخواست معتبر نیست.');
    }
    if (!is_array($parsed) || (array_is_list($parsed) && $parsed !== [])) {
        app_error('ورودی باید یک شیء JSON باشد.');
    }
    return $body = $parsed;
}

function audit(string $action, string $targetId, array $details = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $sql = 'INSERT INTO audit_logs (id,user_id,user_name,action,target_id,details_json,created_at) VALUES (?,?,?,?,?,?,?)';
    db()->prepare($sql)->execute([uid('audit'), $user['id'] ?? null, $user['name'] ?? 'سیستم',
        clean($action, 100), clean($targetId, 64), json_value($details), utc_now()]);
}

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $response = ['ok' => $status < 400, 'csrf' => csrf_token()];
    if ($status < 400) {
        $response['data'] = $data;
    } else {
        $response['error'] = is_string($data) ? $data : 'انجام درخواست ممکن نیست.';
    }
    echo json_value($response);
    exit;
}

// Optional configuration is read only to establish cookie policy. Missing configuration
// remains a friendly, actionable setup error instead of a stack trace or secret dump.
$sessionConfig = [];
try {
    $sessionConfig = config();
} catch (Throwable) {
    // Installer/API can describe which setup step is still missing.
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    $name = (string)($sessionConfig['session']['name'] ?? 'dezhawo_session');
    session_name(preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/D', $name) ? $name : 'dezhawo_session');
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $path = $path === '/' || $path === '.' ? '/' : rtrim($path, '/') . '/';
    $env = $sessionConfig['app']['env'] ?? 'production';
    session_set_cookie_params(['lifetime' => 0, 'path' => $path,
        'secure' => $env === 'production' || (bool)($sessionConfig['session']['secure'] ?? false),
        'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
date_default_timezone_set('UTC');
