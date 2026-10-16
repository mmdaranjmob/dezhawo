<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/Commerce.php';
require_once __DIR__ . '/app/PaymentMethods.php';
require_once __DIR__ . '/app/Digipay.php';
require_once __DIR__ . '/app/Payments.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$receipt = ['success' => false, 'number' => '', 'status' => '', 'paymentStatus' => '', 'message' => 'تأیید پرداخت انجام نشده است.'];
try {
    if (defined('DEZHAWO_DIGIPAY_CALLBACK') && DEZHAWO_DIGIPAY_CALLBACK === true) {
        require_post();
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
            app_error('حجم بازگشت درگاه بیش از حد مجاز است.', 413);
        }
        $posted = str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? body() : $_POST;
        $receipt = handle_digipay_callback($posted, $_GET['s'] ?? null);
    } else {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            app_error('بازگشت از درگاه باید با درخواست GET انجام شود.', 405);
        }
        $receipt = handle_payment_callback($_GET);
    }
} catch (AppError $error) {
    http_response_code($error->status);
    $receipt['message'] = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503);
    error_log('DEZHAWO payment callback failure: ' . get_class($error) . ' code=' . $error->getCode());
    $receipt['message'] = 'تأیید پرداخت موقتاً ممکن نیست. اگر مبلغ از حساب شما کسر شده است، از بخش سفارش‌ها با پشتیبانی تماس بگیرید؛ دوباره پرداخت نکنید.';
}
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$review = ($receipt['status'] ?? '') === 'payment_review' || ($receipt['paymentStatus'] ?? '') === 'refund_pending';
$paid = (bool)($receipt['success'] ?? false);
$title = $paid ? ($review ? 'پرداخت ثبت شد؛ نیاز به پیگیری دارد.' : 'پرداخت تأیید شد.') : 'پرداخت تأیید نشده است.';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f5f4ef">
<script src="assets/theme.js"></script>
<title>نتیجهٔ پرداخت | DEZHAWO</title>
<style>
@font-face{font-family:Vazirmatn;src:url('assets/font-1.woff2') format('woff2');font-weight:100 900;font-display:swap}
*{box-sizing:border-box}body{margin:0;min-height:100svh;display:grid;place-items:center;padding:24px;background:#f5f4ef;color:#0f0f0e;font-family:Vazirmatn,system-ui,sans-serif;line-height:2}.receipt{width:min(100%,520px);background:white;border:1px solid #e3e1d9;border-radius:24px;padding:38px}.brand{font:900 25px Arial,sans-serif;direction:ltr;text-align:right;letter-spacing:-1px}.brand span{color:#ef5d44}.mark{margin:27px 0 18px;display:grid;place-items:center;width:60px;height:60px;border-radius:50%;background:<?= $paid && !$review ? '#e9f0e3' : '#fff1d4' ?>;font-size:27px}h1{font-size:24px;line-height:1.7;margin:0 0 15px}p{font-size:14px;color:#716f66;margin:0}.number{display:flex;justify-content:space-between;gap:14px;border:1px solid #e3e1d9;border-radius:12px;padding:13px 16px;margin-top:24px;font-size:12px}.number strong{direction:ltr;overflow-wrap:anywhere}.button{display:block;background:#0f0f0e;color:white;text-align:center;text-decoration:none;padding:13px;border-radius:13px;font-size:14px;margin-top:25px}.hint{font-size:11px;margin-top:16px}.test{padding:10px;border:1px solid #eadcb3;background:#fff7dc;border-radius:10px;font-size:11px;margin-top:16px}
</style>
<link rel="stylesheet" href="assets/theme.css">
</head>
<body class="theme-page theme-receipt <?= $paid && !$review ? 'payment-success' : 'payment-warning' ?>"><main class="receipt">
<div class="theme-page-header"><div class="brand">DEZHAWO<span>®</span></div><?php require __DIR__ . '/app/theme-control.php'; ?></div>
<div class="mark" aria-hidden="true"><?= $paid && !$review ? '✓' : '!' ?></div>
<h1><?= $escape($title) ?></h1>
<p><?= $escape($receipt['message'] ?? '') ?></p>
<?php if (!empty($receipt['number'])): ?><div class="number"><span>شماره سفارش</span><strong><?= $escape($receipt['number']) ?></strong></div><?php endif; ?>
<?php if (isset($sessionConfig['app']['env']) && $sessionConfig['app']['env'] === 'testing'): ?><p class="test">محیط آزمون توسعه؛ این نتیجه تراکنش بانکی واقعی نیست.</p><?php endif; ?>
<a class="button" href="index.php">بازگشت به فروشگاه و پیگیری سفارش</a>
<p class="hint">پس از ورود به حساب، وضعیت سفارش و گفتگو با پشتیبانی در دسترس است.</p>
</main></body></html>
