<?php
declare(strict_types=1);

function sms_testing_outbox(): ?string
{
    $configuration = config();
    if (($configuration['app']['env'] ?? '') !== 'testing') {
        return null;
    }
    $path = $configuration['testing']['otp_outbox'] ?? '';
    if (!is_string($path) || $path === '' || !str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return null;
    }
    $real = realpath($path);
    $publicRoot = realpath(dirname(__DIR__));
    if ($real === false || !is_dir($real) || !is_writable($real) || $publicRoot === false) {
        return null;
    }
    if ($real === $publicRoot || str_starts_with($real . DIRECTORY_SEPARATOR, $publicRoot . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $real;
}

function sms_configured(): bool
{
    if (sms_testing_outbox() !== null) {
        return true;
    }
    $sms = config()['sms'] ?? [];
    return ($sms['provider'] ?? '') === 'kavenegar'
        && is_string($sms['api_key'] ?? null)
        && preg_match('/^[A-Za-z0-9]{20,200}$/D', $sms['api_key']) === 1
        && function_exists('curl_init');
}

function sms_send_otp(string $phone, string $code, string $challengeId): void
{
    $outbox = sms_testing_outbox();
    if ($outbox !== null) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $challengeId)) {
            app_error('ارسال رمز ورود ممکن نشد.', 503);
        }
        $path = $outbox . DIRECTORY_SEPARATOR . $challengeId . '.json';
        $previousMask = umask(0077);
        $handle = fopen($path, 'x');
        umask($previousMask);
        if ($handle === false) {
            app_error('ارسال رمز ورود در محیط آزمون ممکن نشد.', 503);
        }
        $payload = json_encode(['id' => $challengeId, 'phone' => $phone, 'code' => $code, 'expiresAt' => gmdate('c', time() + 300)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $payload);
        fclose($handle);
        if ($written !== strlen($payload) || !chmod($path, 0600)) {
            @unlink($path);
            app_error('ارسال رمز ورود در محیط آزمون ممکن نشد.', 503);
        }
        return;
    }
    if (!sms_configured()) {
        app_error('سرویس پیامک فروشگاه هنوز تنظیم نشده است.', 503);
    }
    $sms = config()['sms'];
    $fields = ['receptor' => $phone, 'message' => "رمز ورود دژاوو: {$code}\nاین رمز تا ۵ دقیقه معتبر است. رمز را در اختیار دیگران نگذارید."];
    $sender = $sms['sender'] ?? '';
    if (is_string($sender) && $sender !== '') {
        if (!preg_match('/^\+?[0-9]{5,20}$/D', $sender)) {
            app_error('تنظیمات فرستندهٔ پیامک فروشگاه کامل نشده است.', 503);
        }
        $fields['sender'] = $sender;
    }
    // Kavenegar puts the API key in the URL: never include this URL in errors or logs.
    $curl = curl_init('https://api.kavenegar.com/v1/' . rawurlencode($sms['api_key']) . '/sms/send.json');
    if ($curl === false) {
        app_error('اتصال به سرویس پیامک ممکن نشد؛ دوباره تلاش کنید.', 503);
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($response) || $httpStatus < 200 || $httpStatus >= 300 || strlen($response) > 1048576) {
        app_error('ارسال پیامک انجام نشد؛ کمی بعد دوباره تلاش کنید.', 503);
    }
    $result = json_decode($response, true);
    if (!is_array($result) || (int)($result['return']['status'] ?? 0) !== 200) {
        app_error('ارسال پیامک انجام نشد؛ با پشتیبانی فروشگاه تماس بگیرید.', 503);
    }
}
