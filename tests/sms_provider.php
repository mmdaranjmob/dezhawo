<?php
declare(strict_types=1);

namespace DezhawoSmsFixtures;

/*
 * Execute the real provider implementation with a namespaced cURL fixture.
 * No API keys, database, configuration file, or network connection are used.
 * Production contains no fixture switch or transport-injection endpoint.
 */
final class FixtureError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}

final class FixtureCurl
{
    public array $options = [];
    public int $received = 0;
    public bool $aborted = false;

    public function __construct(public readonly string $url)
    {
    }
}

final class Fixture
{
    public static array $config = [];
    public static string $response = '';
    public static int $httpStatus = 200;
    public static bool $networkFailure = false;
    public static bool $initializationFailure = false;
    public static bool $configurationFailure = false;
    public static int $calls = 0;
    public static int $closed = 0;
    public static ?FixtureCurl $handle = null;
}

function config(): array
{
    return Fixture::$config;
}

function app_error(string $message, int $status = 400): never
{
    throw new FixtureError($message, $status);
}

function curl_init(?string $url = null): FixtureCurl|false
{
    if (Fixture::$initializationFailure) {
        return false;
    }
    return Fixture::$handle = new FixtureCurl($url ?? '');
}

function curl_setopt_array(FixtureCurl $handle, array $options): bool
{
    $handle->options = $options + $handle->options;
    return !Fixture::$configurationFailure;
}

function curl_setopt(FixtureCurl $handle, int $option, mixed $value): bool
{
    $handle->options[$option] = $value;
    return true;
}

function curl_exec(FixtureCurl $handle): string|bool
{
    ++Fixture::$calls;
    if (Fixture::$networkFailure) {
        return false;
    }
    $writer = $handle->options[CURLOPT_WRITEFUNCTION] ?? null;
    if (is_callable($writer)) {
        foreach (str_split(Fixture::$response, 8192) as $chunk) {
            $handle->received += strlen($chunk);
            if ($writer($handle, $chunk) !== strlen($chunk)) {
                $handle->aborted = true;
                return false;
            }
        }
        return true;
    }
    $handle->received = strlen(Fixture::$response);
    return Fixture::$response;
}

function curl_getinfo(FixtureCurl $handle, ?int $option = null): mixed
{
    return $option === CURLINFO_HTTP_CODE ? Fixture::$httpStatus : ['http_code' => Fixture::$httpStatus];
}

function curl_error(FixtureCurl $handle): string
{
    return 'Fixture provider failure; credentials and messages must remain private.';
}

function curl_errno(FixtureCurl $handle): int
{
    return Fixture::$networkFailure ? CURLE_COULDNT_CONNECT : ($handle->aborted ? CURLE_WRITE_ERROR : CURLE_OK);
}

function curl_close(FixtureCurl $handle): void
{
    ++Fixture::$closed;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function modernSuccess(): array
{
    return ['success' => true, 'result' => ['resultCode' => 0, 'batchSmsId' => 987654321]];
}

function resetFixture(string $mode = 'api_key'): void
{
    Fixture::$config = [
        'app' => ['env' => 'production'],
        'testing' => ['otp_outbox' => ''],
        'sms' => [
            'provider' => 'niazpardaz',
            'niazpardaz_auth' => $mode,
            'api_key' => 'FixtureApiKey12345678901234567890',
            'sender' => '10001234',
            'username' => 'fixture-account',
            'password' => 'fixture-private-password',
        ],
    ];
    Fixture::$response = json_encode(modernSuccess(), JSON_THROW_ON_ERROR);
    Fixture::$httpStatus = 200;
    Fixture::$networkFailure = false;
    Fixture::$initializationFailure = false;
    Fixture::$configurationFailure = false;
    Fixture::$calls = Fixture::$closed = 0;
    Fixture::$handle = null;
}

function sendOtp(): void
{
    sms_send_otp('09123456789', '123456', 'fixture_challenge');
}

function expectFailure(): void
{
    try {
        sendOtp();
    } catch (FixtureError $error) {
        check($error->status === 503, 'Provider failure must use the temporary service-error status.');
        foreach (['123456', '09123456789', 'FixtureApiKey', 'fixture-private-password', 'fixture-account', 'provider-secret'] as $private) {
            check(!str_contains($error->getMessage(), $private), 'Public errors must not expose messages, credentials, phone numbers, or provider bodies.');
        }
        check(Fixture::$calls <= 1, 'An uncertain send must not be retried or redirected to another endpoint.');
        return;
    }
    throw new \RuntimeException('Invalid provider result was accepted.');
}

function requestBody(): array
{
    $payload = Fixture::$handle?->options[CURLOPT_POSTFIELDS] ?? null;
    check(is_string($payload), 'Provider request body must be serialized.');
    $body = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    check(is_array($body), 'Provider request body must be a JSON object.');
    return $body;
}

function verifySendFields(array $body): void
{
    check(($body['fromNumber'] ?? null) === '10001234', 'The configured sending line must be used.');
    check(($body['toNumbers'] ?? null) === '09123456789', 'The provider requires the recipient as a comma-separated string, with exactly the requested mobile number.');
    check(is_string($body['messageContent'] ?? null) && str_contains($body['messageContent'], '123456'), 'The generated OTP must reach the provider message.');
    check(($body['isFlash'] ?? null) === false && ($body['sendDelay'] ?? null) === 0, 'OTP messages must be immediate, ordinary SMS.');
}

if (!extension_loaded('curl')) {
    fwrite(STDERR, "This optional development check requires PHP with the cURL extension.\n");
    exit(2);
}

$source = file_get_contents(dirname(__DIR__) . '/app/Sms.php');
check(is_string($source), 'Provider source could not be loaded.');
check(stripos($source, chr(92) . 'curl_') === false, 'Global cURL calls bypass the isolated transport fixture; update the harness before running.');
$source = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/', '', $source, 1, $count);
check($count === 1 && is_string($source), 'Provider source preamble changed; update the fixture loader.');
eval('namespace ' . __NAMESPACE__ . ';' . $source);

$tests = [];
$tests['API-key send uses the documented JSON contract and one fixed endpoint'] = static function (): void {
    check(sms_configured(), 'A complete API-key configuration must be recognized.');
    sendOtp();
    check(Fixture::$calls === 1, 'A successful send must execute exactly once.');
    check(Fixture::$handle?->url === 'https://login.niazpardaz.ir/api/v2/RestWebApi/SendBatchSms', 'API-key requests must use the official fixed endpoint.');
    $body = requestBody();
    verifySendFields($body);
    check(!array_key_exists('userName', $body) && !array_key_exists('password', $body), 'API-key mode must not transmit account credentials.');
    $headers = Fixture::$handle?->options[CURLOPT_HTTPHEADER] ?? [];
    check(in_array('X-API-Key: FixtureApiKey12345678901234567890', $headers, true), 'The API key must use the documented header.');
    check(in_array('Content-Type: application/json', $headers, true), 'JSON requests must declare their content type.');
};
$tests['Provider transport verifies TLS, bounds timeouts and rejects redirects'] = static function (): void {
    sendOtp();
    $options = Fixture::$handle?->options ?? [];
    check(($options[CURLOPT_POST] ?? null) === true, 'Sending must use POST.');
    check(($options[CURLOPT_SSL_VERIFYPEER] ?? null) === true && ($options[CURLOPT_SSL_VERIFYHOST] ?? null) === 2, 'TLS peer and hostname verification must be enabled.');
    check(($options[CURLOPT_FOLLOWLOCATION] ?? null) === false, 'Provider redirects must not receive credentials.');
    check(($options[CURLOPT_CONNECTTIMEOUT] ?? null) === 5 && ($options[CURLOPT_TIMEOUT] ?? null) === 15, 'Provider timeouts must remain bounded.');
    check(($options[CURLOPT_PROTOCOLS] ?? null) === CURLPROTO_HTTPS, 'Only HTTPS transport is allowed.');
    check(Fixture::$closed === 1, 'Successful transports must close the handle.');
};
foreach ([
    'PascalCase' => ['ResultCode' => 0, 'BatchSmsId' => 54321],
    'camelCase' => ['resultCode' => 0, 'batchSmsId' => 54321],
    'envelope' => ['success' => true, 'result' => ['resultCode' => 0, 'batchSmsId' => 54321]],
    'PascalCase envelope' => ['success' => true, 'result' => ['ResultCode' => 0, 'BatchSmsId' => 54321]],
] as $format => $response) {
    $tests['Credentials send accepts documented ' . $format . ' result'] = static function () use ($response): void {
        resetFixture('credentials');
        Fixture::$response = json_encode($response, JSON_THROW_ON_ERROR);
        sendOtp();
        check(Fixture::$calls === 1, 'A credentials send must execute exactly once.');
        check(Fixture::$handle?->url === 'https://www.payamak.vip/api/v2/RestWebApi/SendBatchSms', 'Credentials mode must use the documented endpoint.');
        $body = requestBody();
        verifySendFields($body);
        check(($body['userName'] ?? null) === 'fixture-account' && ($body['password'] ?? null) === 'fixture-private-password', 'Credentials must use documented JSON field names.');
        $headers = Fixture::$handle?->options[CURLOPT_HTTPHEADER] ?? [];
        check(!array_filter($headers, static fn ($header): bool => stripos($header, 'X-API-Key:') === 0), 'Credentials mode must not leak an unrelated API key.');
    };
}

foreach ([
    'false success' => ['success' => false, 'result' => ['resultCode' => 0, 'batchSmsId' => 12]],
    'missing success' => ['result' => ['resultCode' => 0, 'batchSmsId' => 12]],
    'nonboolean success' => ['success' => 'true', 'result' => ['resultCode' => 0, 'batchSmsId' => 12]],
    'queued result code 1' => ['success' => true, 'result' => ['resultCode' => 1, 'batchSmsId' => 12]],
    'authentication result code 25' => ['success' => true, 'result' => ['resultCode' => 25, 'batchSmsId' => 12]],
    'missing result code' => ['success' => true, 'result' => ['batchSmsId' => 12]],
    'missing batch ID' => ['success' => true, 'result' => ['resultCode' => 0]],
    'zero batch ID' => ['success' => true, 'result' => ['resultCode' => 0, 'batchSmsId' => 0]],
    'negative batch ID' => ['success' => true, 'result' => ['resultCode' => 0, 'batchSmsId' => -1]],
    'string result code' => ['success' => true, 'result' => ['resultCode' => '0', 'batchSmsId' => 12]],
    'string batch ID' => ['success' => true, 'result' => ['resultCode' => 0, 'batchSmsId' => '12']],
    'unwrapped result' => ['resultCode' => 0, 'batchSmsId' => 12],
    'provider error details' => ['success' => false, 'message' => 'provider-secret', 'result' => null],
] as $label => $response) {
    $tests['API-key mode rejects ' . $label] = static function () use ($response): void {
        Fixture::$response = json_encode($response, JSON_THROW_ON_ERROR);
        expectFailure();
    };
}
foreach ([
    'failed envelope' => ['success' => false, 'result' => ['ResultCode' => 0, 'BatchSmsId' => 12]],
    'incomplete envelope' => ['result' => ['resultCode' => 0, 'batchSmsId' => 12]],
    'queued result code 1' => ['ResultCode' => 1, 'BatchSmsId' => 12],
    'authentication result code 25' => ['resultCode' => 25, 'batchSmsId' => 12],
    'missing batch ID' => ['ResultCode' => 0],
    'conflicting code casing' => ['resultCode' => 0, 'ResultCode' => 25, 'BatchSmsId' => 12],
    'conflicting ID casing' => ['ResultCode' => 0, 'batchSmsId' => 12, 'BatchSmsId' => 0],
] as $label => $response) {
    $tests['Credentials mode rejects ' . $label] = static function () use ($response): void {
        resetFixture('credentials');
        Fixture::$response = json_encode($response, JSON_THROW_ON_ERROR);
        expectFailure();
    };
}
foreach (['empty body' => '', 'HTML gateway page' => '<html>provider-secret</html>', 'malformed JSON' => '{', 'JSON null' => 'null', 'JSON string' => '"provider-secret"', 'empty JSON object' => '{}'] as $label => $response) {
    $tests['Provider rejects ' . $label] = static function () use ($response): void {
        Fixture::$response = $response;
        expectFailure();
    };
}
foreach ([201, 302, 429, 500] as $status) {
    $tests['HTTP ' . $status . ' cannot authenticate delivery'] = static function () use ($status): void {
        Fixture::$httpStatus = $status;
        expectFailure();
    };
}
$tests['Network failure produces a generic error without retry'] = static function (): void {
    Fixture::$networkFailure = true;
    expectFailure();
    check(Fixture::$calls === 1, 'A failed network send must not be retried.');
    check(Fixture::$closed === 1, 'Failed transports must close the handle.');
};
$tests['Transport initialization failure fails closed'] = static function (): void {
    Fixture::$initializationFailure = true;
    expectFailure();
    check(Fixture::$calls === 0, 'A failed initialization must not execute a request.');
};
$tests['Transport configuration failure fails closed and closes the handle'] = static function (): void {
    Fixture::$configurationFailure = true;
    expectFailure();
    check(Fixture::$calls === 0 && Fixture::$closed === 1, 'A failed transport setup must close its handle without sending.');
};
$tests['Oversized response is aborted during transfer'] = static function (): void {
    Fixture::$response = str_repeat(' ', 1048576) . json_encode(modernSuccess(), JSON_THROW_ON_ERROR);
    expectFailure();
    check(Fixture::$handle?->aborted === true, 'Oversized bodies must be bounded during transfer, before buffering the full response.');
    check((Fixture::$handle?->received ?? 0) <= 1048576 + 8192, 'The transport must stop after crossing the one-megabyte response limit.');
};

foreach ([
    'missing sender' => ['sender', ''],
    'invalid sender' => ['sender', 'not-a-sending-line'],
    'sender header injection' => ['sender', "10001234\r\nX-Evil: injected"],
    'missing API key' => ['api_key', ''],
    'API-key header injection' => ['api_key', "fixture-key\r\nX-Evil: injected"],
    'API-key whitespace' => ['api_key', 'fixture key'],
    'unsupported authentication mode' => ['niazpardaz_auth', 'guess'],
] as $label => [$key, $value]) {
    $tests['Configuration rejects ' . $label . ' before sending'] = static function () use ($key, $value): void {
        Fixture::$config['sms'][$key] = $value;
        check(!sms_configured(), 'Incomplete or unsafe provider settings must not be considered configured.');
        expectFailure();
        check(Fixture::$calls === 0, 'Invalid provider settings must never start transport.');
    };
}
foreach (['username', 'password'] as $key) {
    $tests['Credentials mode requires ' . $key] = static function () use ($key): void {
        resetFixture('credentials');
        Fixture::$config['sms'][$key] = '';
        check(!sms_configured(), 'Credentials mode requires both account fields.');
        expectFailure();
        check(Fixture::$calls === 0, 'Incomplete credentials must not be sent.');
    };
}
$tests['Production ignores testing outbox and uses the real provider path'] = static function (): void {
    $directory = sys_get_temp_dir() . '/dezhawo-sms-fixture-' . bin2hex(random_bytes(8));
    check(mkdir($directory, 0700), 'Could not create the private fixture directory.');
    try {
        Fixture::$config['testing']['otp_outbox'] = $directory;
        check(sms_testing_outbox() === null, 'Production must ignore testing outbox settings.');
        sendOtp();
        check(Fixture::$calls === 1 && glob($directory . '/*') === [], 'Production must send through the provider without writing an OTP file.');
    } finally {
        rmdir($directory);
    }
};
$tests['Explicit testing outbox remains private and bypasses transport'] = static function (): void {
    $directory = sys_get_temp_dir() . '/dezhawo-sms-fixture-' . bin2hex(random_bytes(8));
    check(mkdir($directory, 0700), 'Could not create the private fixture directory.');
    $path = $directory . '/fixture_challenge.json';
    try {
        Fixture::$config['app']['env'] = 'testing';
        Fixture::$config['testing']['otp_outbox'] = $directory;
        sendOtp();
        check(Fixture::$calls === 0 && is_file($path), 'Explicit testing must use only the private outbox.');
        check((fileperms($path) & 0777) === 0600, 'OTP fixture files must be readable only by their owner.');
        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        check(($payload['phone'] ?? null) === '09123456789' && ($payload['code'] ?? null) === '123456', 'The private testing outbox must retain its existing payload contract.');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        rmdir($directory);
    }
};
$tests['Kavenegar remains compatible with existing settings'] = static function (): void {
    Fixture::$config['sms']['provider'] = 'kavenegar';
    Fixture::$response = '{"return":{"status":200}}';
    check(sms_configured(), 'Existing Kavenegar configurations must remain valid.');
    sendOtp();
    check(Fixture::$handle?->url === 'https://api.kavenegar.com/v1/FixtureApiKey12345678901234567890/sms/send.json', 'Existing Kavenegar sends must keep their endpoint.');
    $fields = [];
    parse_str(Fixture::$handle?->options[CURLOPT_POSTFIELDS] ?? '', $fields);
    check(($fields['receptor'] ?? null) === '09123456789' && ($fields['sender'] ?? null) === '10001234', 'Existing Kavenegar field names must remain compatible.');
    check(is_string($fields['message'] ?? null) && str_contains($fields['message'], '123456'), 'Kavenegar must receive the generated OTP.');
};

$failed = 0;
foreach ($tests as $label => $test) {
    resetFixture();
    try {
        $test();
        echo 'PASS ', $label, "\n";
    } catch (\Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $label . ': ' . $error->getMessage() . "\n");
    }
}
echo count($tests) - $failed, '/', count($tests), " SMS provider fixture checks passed. No external SMS requests were sent.\n";
exit($failed === 0 ? 0 : 1);
