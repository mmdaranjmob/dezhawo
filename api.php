<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/TemporaryLogin.php';
require_once __DIR__ . '/app/Sms.php';
require_once __DIR__ . '/app/PaymentMethods.php';
require_once __DIR__ . '/app/Categories.php';
require_once __DIR__ . '/app/Commerce.php';
require_once __DIR__ . '/app/Payments.php';
require_once __DIR__ . '/app/Digipay.php';
require_once __DIR__ . '/app/ManualPayments.php';
require_once __DIR__ . '/app/ProductImages.php';
require_once __DIR__ . '/app/Admin.php';
require_once __DIR__ . '/app/DatabaseUpgrades.php';

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
try {
    if ($action === 'bootstrap') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            app_error('اطلاعات فروشگاه با درخواست GET دریافت می‌شود.', 405);
        }
        config();
        $owner = db()->query("SELECT id FROM users WHERE role_id='owner' LIMIT 1")->fetch();
        if (!$owner && !temporary_login_enabled()) {
            respond(['configured' => false, 'message' => 'دیتابیس آماده است؛ مدیر فروشگاه را از صفحهٔ install.php ایجاد کنید.']);
        }
        respond(bootstrap_data());
    }
    $allowed = [
        'auth.requestOtp', 'auth.verifyOtp', 'auth.temporaryLogin', 'auth.logout',
        'database.upgrade',
        'cart.add', 'cart.set', 'reservation.create', 'reservation.release', 'order.checkout', 'order.resume',
        'pos.sale', 'pos.customerLookup', 'inventory.adjust', 'order.status', 'order.tracking',
        'product.save', 'product.imageUpload', 'product.enabled', 'variant.enabled', 'product.delete', 'category.save', 'category.delete', 'settings.save',
        'payments.settings', 'transfer.submit', 'transfer.review', 'order.deliver',
        'shipping.save', 'shipping.disable', 'role.save', 'role.delete',
        'user.role', 'user.enabled', 'user.create',
        'ticket.create', 'ticket.reply', 'ticket.status',
    ];
    if (!in_array($action, $allowed, true)) {
        app_error('این مسیر در فروشگاه وجود ندارد.', 404);
    }
    require_post();
    check_csrf();
    if ($action === 'auth.temporaryLogin') {
        respond(auth_temporary_login(body()));
    }
    if ($action === 'database.upgrade') {
        respond(database_upgrade_apply(body()));
    }
    $owner = db()->query("SELECT id FROM users WHERE role_id='owner' LIMIT 1")->fetch();
    if (!$owner) {
        app_error('ابتدا مدیر فروشگاه را از نصب‌کننده ایجاد کنید.', 503);
    }
    if (str_starts_with($action, 'auth.')) {
        respond(auth_action($action, body()));
    }
    if (database_upgrade_status()['recovery']) {
        app_error('فروشگاه برای ادامه به ارتقای دیتابیس نیاز دارد. مدیر اصلی پس از ورود، دکمهٔ «ارتقای دیتابیس» بالای سایت را بزند.',503);
    }
    if ($action === 'product.imageUpload') {
        respond(product_image_upload());
    }
    $input = body();
    if (str_starts_with($action, 'transfer.')) {
        respond(manual_payment_action($action, $input));
    }
    if ($action === 'order.deliver') {
        respond(payment_deliver($input));
    }
    if (in_array($action, ['cart.add', 'cart.set', 'reservation.create', 'reservation.release',
        'order.checkout', 'order.resume', 'pos.sale', 'pos.customerLookup', 'inventory.adjust', 'order.status', 'order.tracking'], true)) {
        respond(commerce_action($action, $input));
    }
    respond(admin_action($action, $input));
} catch (AppError $error) {
    if ($action === 'bootstrap' && $error->status === 503) {
        respond(['configured' => false, 'message' => $error->getMessage()]);
    }
    respond($error->getMessage(), $error->status);
} catch (Throwable $error) {
    // Do not log exception messages: DSNs, query parameters and provider URLs can
    // contain private connection details. The error type/code is enough to diagnose.
    error_log('DEZHAWO API failure: ' . get_class($error) . ' code=' . $error->getCode());
    if ($action === 'bootstrap') {
        respond(['configured' => false, 'message' => 'اتصال دیتابیس یا جدول‌ها آماده نیست. تنظیمات اتصال و واردکردن database/schema.sql را طبق راهنما بررسی کنید.']);
    }
    respond('انجام عملیات موقتاً ممکن نیست؛ دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.', 500);
}
