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
    if (!is_array($sms) || !function_exists('curl_init')) {
        return false;
    }
    if (($sms['provider'] ?? '') === 'kavenegar') {
        return is_string($sms['api_key'] ?? null)
            && preg_match('/^[A-Za-z0-9]{20,200}$/D', $sms['api_key']) === 1
            && (($sms['sender'] ?? '') === '' || sms_sender_valid($sms['sender']));
    }
    if (($sms['provider'] ?? '') !== 'niazpardaz' || !sms_sender_valid($sms['sender'] ?? null)) {
        return false;
    }
    return match ($sms['niazpardaz_auth'] ?? 'api_key') {
        // The SDK accepts an opaque key; reject whitespace/control characters in headers.
        'api_key' => is_string($sms['api_key'] ?? null)
            && preg_match('/^[\x21-\x7E]{1,4096}$/D', $sms['api_key']) === 1,
        'credentials' => sms_credential_valid($sms['username'] ?? null)
            && sms_credential_valid($sms['password'] ?? null),
        default => false,
    };
}

function sms_sender_valid(mixed $sender): bool
{
    return is_string($sender) && preg_match('/^\+?[0-9]{5,20}$/D', $sender) === 1;
}

function sms_credential_valid(mixed $value): bool
{
    return is_string($value) && trim($value) !== '' && strlen($value) <= 4096 && !str_contains($value, "\0");
}

/** Fixed endpoints only: account credentials never travel to a configurable URL. */
function sms_provider_request(array $sms, string $phone, string $message): array
{
    if ($sms['provider'] === 'niazpardaz') {
        $fields = ['fromNumber' => $sms['sender'], 'toNumbers' => $phone,
            'messageContent' => $message, 'isFlash' => false, 'sendDelay' => 0];
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (($sms['niazpardaz_auth'] ?? 'api_key') === 'credentials') {
            // Username/password method documented in the supplied REST reference.
            $url = 'https://www.payamak.vip/api/v2/RestWebApi/SendBatchSms';
            $fields['userName'] = $sms['username'];
            $fields['password'] = $sms['password'];
        } else {
            // Protocol verified against the official NiazpardazSms/niazpardaz-sms-php SDK.
            $url = 'https://login.niazpardaz.ir/api/v2/RestWebApi/SendBatchSms';
            $headers[] = 'X-API-Key: ' . $sms['api_key'];
        }
        try {
            $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            app_error('تنظیمات سرویس پیامک معتبر نیست.', 503);
        }
        return ['url' => $url, 'body' => $body, 'headers' => $headers];
    }
    $fields = ['receptor' => $phone, 'message' => $message];
    if (($sms['sender'] ?? '') !== '') {
        $fields['sender'] = $sms['sender'];
    }
    // Kavenegar puts the key in the URL: never log this request or provider responses.
    return ['url' => 'https://api.kavenegar.com/v1/' . rawurlencode($sms['api_key']) . '/sms/send.json',
        'body' => http_build_query($fields),
        'headers' => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']];
}

/** One transport attempt; no automatic retry can send an OTP twice. */
function sms_http_send(array $request): array
{
    $curl = curl_init($request['url']);
    if ($curl === false) {
        app_error('اتصال به سرویس پیامک ممکن نشد؛ دوباره تلاش کنید.', 503);
    }
    $response = '';
    try {
        $configured = curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['body'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $request['headers'],
            // Bound memory during download, including HTML/error bodies from a provider.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                $length = strlen($chunk);
                if (strlen($response) + $length > 1048576) {
                    return 0;
                }
                $response .= $chunk;
                return $length;
            },
        ]);
        if (!$configured) {
            app_error('اتصال به سرویس پیامک ممکن نشد؛ دوباره تلاش کنید.', 503);
        }
        $sent = curl_exec($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    if ($sent === false || $httpStatus < 200 || $httpStatus >= 300) {
        app_error('ارسال پیامک انجام نشد؛ کمی بعد دوباره تلاش کنید.', 503);
    }
    return ['status' => $httpStatus, 'data' => json_decode($response, true)];
}

function sms_niazpardaz_accepted(mixed $data, bool $credentials): bool
{
    if (!is_array($data)) {
        return false;
    }
    if (array_key_exists('success', $data)) {
        if ($data['success'] !== true) {
            return false;
        }
        $data = $data['result'] ?? null;
    } elseif (!$credentials) {
        return false;
    }
    if (!is_array($data)) {
        return false;
    }
    $code = $data['resultCode'] ?? ($credentials ? ($data['ResultCode'] ?? null) : null);
    $batch = $data['batchSmsId'] ?? ($credentials ? ($data['BatchSmsId'] ?? null) : null);
    if ($credentials && ((isset($data['resultCode'], $data['ResultCode']) && $data['resultCode'] !== $data['ResultCode'])
        || (isset($data['batchSmsId'], $data['BatchSmsId']) && $data['batchSmsId'] !== $data['BatchSmsId']))) {
        return false;
    }
    return $code === 0 && is_int($batch) && $batch > 0;
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
    $message = "رمز ورود دژاوو: {$code}\nاین رمز تا ۵ دقیقه معتبر است. رمز را در اختیار دیگران نگذارید.";
    $response = sms_http_send(sms_provider_request($sms, $phone, $message));
    $data = $response['data'];
    $accepted = $sms['provider'] === 'niazpardaz'
        ? $response['status'] === 200 && sms_niazpardaz_accepted($data, ($sms['niazpardaz_auth'] ?? 'api_key') === 'credentials')
        : is_array($data) && ($data['return']['status'] ?? null) === 200;
    if (!$accepted) {
        app_error('ارسال پیامک انجام نشد؛ با پشتیبانی فروشگاه تماس بگیرید.', 503);
    }
}
