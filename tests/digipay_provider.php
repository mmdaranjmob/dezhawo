<?php
declare(strict_types=1);

namespace {
    /* Optional development-only harness. The production gateway has no mock switch. */
    if (!in_array('--database', $argv ?? [], true)) {
        fwrite(STDERR, "Run with --database against the disposable testing runtime; no provider requests are sent.\n");
        exit(2);
    }
    require dirname(__DIR__) . '/app/bootstrap.php';
    require dirname(__DIR__) . '/app/Commerce.php';
    require dirname(__DIR__) . '/app/Payments.php';
    $qaConfig = config();
    if (($qaConfig['app']['env'] ?? '') !== 'testing'
        || !str_ends_with((string) ($qaConfig['database']['name'] ?? ''), '_qa')
        || !str_ends_with((string) db()->query('SELECT DATABASE()')->fetchColumn(), '_qa')) {
        fwrite(STDERR, "Refusing to write outside a disposable *_qa testing database.\n");
        exit(2);
    }
    if (!extension_loaded('curl')) {
        fwrite(STDERR, "The optional provider fixtures require the PHP cURL extension.\n");
        exit(2);
    }
}

namespace DezhawoDigipayFixtures {
    use AppError;
    use JsonException;
    use LogicException;
    use Throwable;

    final class Handle
    {
        public array $options = [];
        public bool $aborted = false;
        public int $received = 0;
        public function __construct(public readonly string $url) {}
    }

    final class Fixture
    {
        public static array $config = [];
        public static array $queue = [];
        public static array $calls = [];
        public static int $http = 200;
        public static int $closed = 0;
        public static bool $networkFailure = false;
        public static bool $initializationFailure = false;
        public static bool $configurationFailure = false;
        public static ?Handle $handle = null;
        public static bool $authorized = true;
    }

    function config(): array { return Fixture::$config; }
    function payment_require_configured(string $method = 'zarinpal', bool $checkEnabled = true): void
    {
        check($method === 'digipay', 'The provider must not select a different gateway.');
        digipay_require_credentials($checkEnabled);
    }
    function require_permission(string $permission): array
    {
        if (!Fixture::$authorized) \app_error('Fixture permission denied.', 403);
        check($permission === 'orders.edit', 'Delivery requires order editing permission.');
        return ['id' => 'fixture_actor', 'name' => 'Fixture'];
    }
    function curl_init(?string $url = null): Handle|false
    {
        if (Fixture::$initializationFailure) return false;
        return Fixture::$handle = new Handle($url ?? '');
    }
    function curl_setopt_array(Handle $handle, array $options): bool
    {
        $handle->options = $options + $handle->options;
        return !Fixture::$configurationFailure;
    }
    function curl_exec(Handle $handle): bool
    {
        Fixture::$calls[] = $handle;
        if (Fixture::$networkFailure) return false;
        check(count(Fixture::$queue) > 0, 'Transport fixture ran out of responses; no real network is available.');
        $response = array_shift(Fixture::$queue);
        Fixture::$http = $response['http'];
        $writer = $handle->options[CURLOPT_WRITEFUNCTION] ?? null;
        check(is_callable($writer), 'The provider must bound response data while streaming.');
        foreach (str_split($response['body'], 8192) as $chunk) {
            $handle->received += strlen($chunk);
            if ($writer($handle, $chunk) !== strlen($chunk)) {
                $handle->aborted = true;
                return false;
            }
        }
        return true;
    }
    function curl_getinfo(Handle $handle, ?int $option = null): mixed
    {
        return $option === CURLINFO_RESPONSE_CODE || $option === CURLINFO_HTTP_CODE ? Fixture::$http : [];
    }
    function curl_errno(Handle $handle): int
    {
        return Fixture::$networkFailure ? CURLE_COULDNT_CONNECT : ($handle->aborted ? CURLE_WRITE_ERROR : CURLE_OK);
    }
    function curl_close(Handle $handle): void { ++Fixture::$closed; }

    function check(bool $value, string $message): void
    {
        if (!$value) throw new \RuntimeException($message);
    }
    function response(mixed $data, int $http = 200, bool $raw = false): void
    {
        Fixture::$queue[] = ['http' => $http, 'body' => $raw ? $data : json_encode($data, JSON_THROW_ON_ERROR)];
    }
    function success(array $fields = []): array { return ['result' => ['status' => 0]] + $fields; }
    function resetFixture(): void
    {
        Fixture::$config = [
            'app' => ['env' => 'production', 'base_url' => 'https://shop.example'],
            'payment' => ['digipay' => [
                'username' => 'fixture-user', 'password' => 'fixture-private-password',
                'client_id' => 'fixture-client', 'client_secret' => 'fixture-private-secret',
                'sandbox' => false, 'seller_id' => 'fixture-seller', 'supplier_id' => 'fixture-supplier',
                'category_id' => 'fixture-category', 'brand' => 'DEZHAWO', 'product_type' => 1,
            ]],
        ];
        Fixture::$queue = Fixture::$calls = [];
        Fixture::$networkFailure = Fixture::$initializationFailure = Fixture::$configurationFailure = false;
        Fixture::$authorized = true;
        Fixture::$closed = 0;
        Fixture::$handle = null;
        response(['access_token' => 'fixture-access-token', 'token_type' => 'bearer', 'expires_in' => 3599]);
        check(digipay_access_token(true) === 'fixture-access-token', 'Fixture token setup failed.');
        Fixture::$calls = [];
        Fixture::$closed = 0;
    }
    function fail(callable $operation, ?int $status = null): void
    {
        try { $operation(); } catch (AppError $error) {
            if ($status !== null) check($error->status === $status, 'Failure used an unexpected HTTP status.');
            foreach (['fixture-private', 'fixture-access-token', 'fixture-user', 'provider-secret'] as $private) {
                check(!str_contains($error->getMessage(), $private), 'Public provider errors leaked a credential or provider body.');
            }
            return;
        }
        throw new \RuntimeException('The invalid provider operation was accepted.');
    }
    function statement(string $sql, array $params = []): \PDOStatement
    {
        $query = \db()->prepare($sql); $query->execute($params); return $query;
    }
    function seedOrder(int $stock = 2): array
    {
        $data = ['user' => \uid('dgqa_user'), 'product' => \uid('dgqa_product'),
                 'variant' => \uid('dgqa_variant'), 'reservation' => \uid('dgqa_reserve'),
                 'order' => \uid('dgqa_order'), 'cart' => bin2hex(random_bytes(32))];
        $data['number'] = 'DGQA-' . bin2hex(random_bytes(12));
        statement("INSERT INTO users(id,name,phone,role_id,enabled) VALUES (?,'Fixture purchaser',?,'customer',1)",
                  [$data['user'], '09' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT)]);
        statement("INSERT INTO products(id,title,category,description,price,cost,enabled) VALUES (?,'Fixture product','case','',120000,70000,1)", [$data['product']]);
        statement("INSERT INTO product_variants(id,product_id,name,stock,enabled) VALUES (?,?,'Fixture variant',?,1)", [$data['variant'], $data['product'], $stock]);
        statement("INSERT INTO reservations(id,cart_key,user_id,status,expires_at) VALUES (?,?,?,'payment_pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))", [$data['reservation'], $data['cart'], $data['user']]);
        statement("INSERT INTO orders(id,number,type,user_id,customer_name,customer_phone,status,payment_status,subtotal,total,payment,reservation_id) VALUES (?,?,'online',?,'Fixture purchaser','09123456789','awaiting_payment','unpaid',120000,120000,'digipay',?)",
                  [$data['order'], $data['number'], $data['user'], $data['reservation']]);
        statement("UPDATE reservations SET order_id=? WHERE id=?", [$data['order'], $data['reservation']]);
        statement("INSERT INTO order_items(order_id,product_id,variant_id,title,variant_name,category,quantity,unit_price,unit_cost) VALUES (?,?,?,'Fixture product','Fixture variant','case',1,120000,70000)", [$data['order'], $data['product'], $data['variant']]);
        statement("INSERT INTO reservation_items(reservation_id,product_id,variant_id,quantity,unit_price,unit_cost) VALUES (?,?,?,1,120000,70000)", [$data['reservation'], $data['product'], $data['variant']]);
        return $data;
    }
    function stored(array $purchase): array
    {
        return statement('SELECT * FROM payment_attempts WHERE order_id=?', [$purchase['order']])->fetch();
    }
    function ticketPayload(): array
    {
        foreach (array_reverse(Fixture::$calls) as $request) {
            if (str_contains($request->url, '/tickets/business?type=11')) {
                return json_decode($request->options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw new \RuntimeException('Ticket request missing.');
    }
    function purchase(int $stock = 2): array
    {
        $purchase = seedOrder($stock);
        response(success(['ticket' => 'fixture-ticket', 'redirectUrl' => 'https://web.mydigipay.com/fixture-ticket']));
        check(digipay_create($purchase['order']) === 'https://web.mydigipay.com/fixture-ticket', 'Successful ticket redirect missing.');
        $purchase['payload'] = ticketPayload();
        parse_str(parse_url($purchase['payload']['callbackUrl'], PHP_URL_QUERY), $query);
        $purchase['state'] = $query['s'];
        $purchase['callback'] = ['amount' => $purchase['payload']['amount'], 'providerId' => $purchase['payload']['providerId'],
                                 'trackingCode' => 'fixture-tracking-code', 'type' => '13', 'result' => 'SUCCESS'];
        return $purchase;
    }
    function verified(array $purchase, array $changes = []): void
    {
        response(success(array_replace(['providerId' => $purchase['callback']['providerId'],
                         'trackingCode' => $purchase['callback']['trackingCode'],
                         'amount' => $purchase['callback']['amount']], $changes)));
    }
    function stock(array $purchase): int
    {
        return (int) statement('SELECT stock FROM product_variants WHERE id=?', [$purchase['variant']])->fetchColumn();
    }
    function assertUnpaid(array $purchase): void
    {
        check(statement('SELECT payment_status FROM orders WHERE id=?', [$purchase['order']])->fetchColumn() === 'unpaid', 'Unverified response finalized payment.');
        check(stock($purchase) === 2, 'Unverified response allocated physical stock.');
    }

    $source = file_get_contents(dirname(__DIR__) . '/app/Digipay.php');
    check(is_string($source) && stripos($source, chr(92) . 'curl_') === false, 'Provider source cannot bypass the namespaced transport fixture.');
    $source = preg_replace('/^<\?php\s*declare\(strict_types=1\);\s*/', '', $source, 1, $count);
    check($count === 1 && is_string($source), 'Update the fixture loader for the provider preamble.');
    eval('namespace ' . __NAMESPACE__ . '; use \\AppError; use \\JsonException; use \\LogicException; use \\Throwable;' . $source);

    $tests = [];
    $tests['OAuth uses Basic authentication and private multipart fields on one fixed endpoint'] = static function (): void {
        response(['access_token' => 'fixture-refreshed-token']);
        check(digipay_access_token(true) === 'fixture-refreshed-token', 'OAuth token missing.');
        $request = Fixture::$calls[0];
        check($request->url === 'https://api.mydigipay.com/digipay/api/oauth/token', 'OAuth endpoint is not fixed.');
        check($request->options[CURLOPT_POSTFIELDS] === ['username' => 'fixture-user', 'password' => 'fixture-private-password', 'grant_type' => 'password'], 'OAuth multipart fields changed.');
        check(in_array('Authorization: Basic ' . base64_encode('fixture-client:fixture-private-secret'), $request->options[CURLOPT_HTTPHEADER], true), 'OAuth Basic header missing.');
        check(!str_contains($request->url, 'fixture'), 'Credentials must not enter URLs.');
    };
    $tests['Transport verifies TLS, refuses redirects and bounds streamed responses'] = static function (): void {
        response(success()); digipay_call('purchases/verify', 13, ['trackingCode' => 'fixture']);
        $request = Fixture::$calls[0]; $options = $request->options;
        check(($options[CURLOPT_SSL_VERIFYPEER] ?? null) === true && ($options[CURLOPT_SSL_VERIFYHOST] ?? null) === 2, 'TLS verification disabled.');
        check(($options[CURLOPT_FOLLOWLOCATION] ?? null) === false && ($options[CURLOPT_PROTOCOLS] ?? null) === CURLPROTO_HTTPS, 'Transport redirects or insecure protocols allowed.');
        check(($options[CURLOPT_CONNECTTIMEOUT] ?? 0) <= 10 && ($options[CURLOPT_TIMEOUT] ?? 0) <= 35, 'Transport timeouts unbounded.');
        check(in_array('Authorization: Bearer fixture-access-token', $options[CURLOPT_HTTPHEADER], true), 'Protected call token missing.');
        response(str_repeat(' ', 1048576) . '{}', 200, true);
        fail(static fn() => digipay_call('purchases/verify', 13, []), 502);
        check(Fixture::$handle?->aborted === true && Fixture::$handle->received <= 1048576 + 8192, 'Oversized response not aborted during transfer.');
    };
    $tests['A definite 401 renews authentication once before repeating the protected request'] = static function (): void {
        response(['error' => 'expired'], 401); response(['access_token' => 'fixture-renewed-token']); response(success());
        check(digipay_success(digipay_call('purchases/verify', 13, [])), 'Renewed request failed.');
        check(count(Fixture::$calls) === 3 && str_ends_with(Fixture::$calls[1]->url, '/oauth/token'), '401 did not use one bounded reauthentication.');
    };
    foreach (['username', 'password', 'client_id', 'client_secret', 'seller_id', 'supplier_id', 'category_id'] as $key) {
        $tests['Incomplete ' . $key . ' configuration fails before ticket transport'] = static function () use ($key): void {
            Fixture::$config['payment']['digipay'][$key] = '';
            fail(static fn() => digipay_call('tickets/business', 11, []), 503);
            check(Fixture::$calls === [], 'Incomplete configuration started an HTTP request.');
        };
    }
    foreach (['initializationFailure', 'configurationFailure'] as $field) {
        $tests['Transport ' . $field . ' fails closed'] = static function () use ($field): void {
            Fixture::${$field} = true;
            fail(static fn() => digipay_call('purchases/verify', 13, []), 503);
            check(Fixture::$calls === [], 'Transport setup failure still executed a request.');
        };
    }
    foreach (['https://evil.example/ticket', 'http://web.mydigipay.com/ticket', 'https://web.mydigipay.com.evil.example/', 'https://user@web.mydigipay.com/', 'https://web.mydigipay.com:444/'] as $redirect) {
        $tests['Ticket rejects unsafe redirect ' . $redirect] = static function () use ($redirect): void {
            $data = seedOrder(); response(success(['ticket' => 'fixture-ticket', 'redirectUrl' => $redirect]));
            fail(static fn() => digipay_create($data['order']), 502);
            check(stored($data)['status'] === 'request_unknown', 'Unusable ticket response should remain uncertain.');
        };
    }
    $tests['UPG ticket uses type 11, one Rial conversion and the configured basket identifiers'] = static function (): void {
        $data = purchase(); $payload = $data['payload'];
        check($payload['amount'] === 1200000 && $payload['cellNumber'] === '09123456789', 'Ticket amount or normalized phone changed.');
        check($payload['providerId'] === stored($data)['id'], 'Ticket identifier was not persisted before request.');
        check(str_starts_with($payload['callbackUrl'], 'https://shop.example/digipay-callback.php?s=') && strlen($data['state']) === 64, 'Callback requires a strong, bound state.');
        $item = $payload['basketDetailsDto']['items'][0];
        check($item === ['sellerId' => 'fixture-seller', 'supplierId' => 'fixture-supplier', 'productCode' => $data['variant'], 'brand' => 'DEZHAWO', 'productType' => 1, 'count' => 1, 'categoryId' => 'fixture-category'], 'Credit basket contract changed.');
        check(!isset($payload['additionalInfo']['preferredGateway']), 'The provider must choose only contract-authorized methods.');
        $metadata = stored($data)['metadata_json'];
        foreach (['fixture-private', 'fixture-access-token', $data['state']] as $private) check(!str_contains($metadata, $private), 'Attempt metadata leaked credentials, access token or raw callback state.');
        $count = count(Fixture::$calls); digipay_create($data['order']);
        check(count(Fixture::$calls) === $count, 'Resuming an existing ticket made a second create request.');
    };
    $tests['An uncertain ticket request is never automatically repeated'] = static function (): void {
        $data = seedOrder(); Fixture::$networkFailure = true;
        fail(static fn() => digipay_create($data['order']), 502);
        check(stored($data)['status'] === 'request_unknown' && count(Fixture::$calls) === 1, 'Uncertain creation must persist without automatic retry.');
        Fixture::$networkFailure = false;
        fail(static fn() => digipay_create($data['order']), 409);
        check(count(Fixture::$calls) === 1, 'Resume repeated an uncertain create request.');
        assertUnpaid($data);
    };
    $tests['Spoofed callback state or amount cannot trigger provider verification'] = static function (): void {
        $data = purchase(); $calls = count(Fixture::$calls);
        fail(static fn() => handle_digipay_callback($data['callback'], str_repeat('0', 64)), 409);
        $bad = $data['callback']; ++$bad['amount'];
        fail(static fn() => handle_digipay_callback($bad, $data['state']), 409);
        check(count(Fixture::$calls) === $calls, 'Unbound callback triggered provider HTTP.'); assertUnpaid($data);
    };
    foreach (['amount' => 1200010, 'providerId' => 'fixture-other-provider', 'trackingCode' => 'fixture-other-tracking'] as $field => $wrong) {
        $tests['Provider verification with mismatched ' . $field . ' cannot finalize'] = static function () use ($field, $wrong): void {
            $data = purchase(); verified($data, [$field => $wrong]);
            fail(static fn() => handle_digipay_callback($data['callback'], $data['state']), 409);
            assertUnpaid($data);
        };
    }
    foreach ([9011, 9005, 9012] as $status) {
        $tests['Provider status ' . $status . ' never establishes payment from posted SUCCESS'] = static function () use ($status): void {
            $data = purchase(); response(['result' => ['status' => $status, 'message' => 'provider-secret']]);
            $result = handle_digipay_callback($data['callback'], $data['state']);
            check(!$result['success'], 'Undocumented or uncertain provider status accepted.'); assertUnpaid($data);
            check(count(Fixture::$calls) === 2, 'Unknown verification status caused an automatic new ticket.');
        };
    }
    $tests['A failed callback cannot pin an attacker tracking code before a verified retry'] = static function (): void {
        $data = purchase(); $bad = $data['callback']; $bad['trackingCode'] = 'fixture-attacker-code';
        response(['result' => ['status' => 9011]]); handle_digipay_callback($bad, $data['state']);
        verified($data); $result = handle_digipay_callback($data['callback'], $data['state']);
        check($result['success'] && stock($data) === 1, 'An unverified callback prevented the legitimate verified callback.');
    };
    $tests['Verified callback allocates exactly once and replay needs no provider request'] = static function (): void {
        $data = purchase(); verified($data);
        $result = handle_digipay_callback($data['callback'], $data['state']);
        check($result['success'] && stock($data) === 1, 'Server-verified payment did not allocate once.');
        $calls = count(Fixture::$calls); handle_digipay_callback($data['callback'], $data['state']);
        check(stock($data) === 1 && count(Fixture::$calls) === $calls, 'Local verified replay mutated stock or reverified.');
        $bad = $data['callback']; $bad['trackingCode'] = 'fixture-replayed-different-code';
        fail(static fn() => handle_digipay_callback($bad, $data['state']), 409);
    };
    $tests['Delivery is permission-controlled, follows completion and supports idempotent retry'] = static function (): void {
        $data = purchase(); verified($data); handle_digipay_callback($data['callback'], $data['state']);
        $calls = count(Fixture::$calls);
        fail(static fn() => payment_deliver(['orderId' => $data['order']]), 409);
        check(count(Fixture::$calls) === $calls, 'Provider delivery was reported before actual completion.');
        statement("UPDATE orders SET status='completed' WHERE id=?", [$data['order']]);
        Fixture::$authorized = false; fail(static fn() => payment_deliver(['orderId' => $data['order']]), 403); Fixture::$authorized = true;
        response(['result' => ['status' => 9011]]);
        check(payment_deliver(['orderId' => $data['order']])['deliveryReport']['status'] === 'failed', 'Uncertain delivery must remain retryable.');
        $first = json_decode(Fixture::$handle->options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
        response(success());
        check(payment_deliver(['orderId' => $data['order']])['deliveryReport']['status'] === 'reported', 'Successful delivery retry not recorded.');
        $last = Fixture::$handle; $payload = json_decode($last->options[CURLOPT_POSTFIELDS], true, 512, JSON_THROW_ON_ERROR);
        check(str_ends_with($last->url, '/purchases/deliver?type=13'), 'BNPL delivery requires the original verified type.');
        check($payload['trackingCode'] === $data['callback']['trackingCode'] && $payload['invoiceNumber'] === $data['number'] && $payload['products'] === [$data['variant']], 'Delivery must use stored verified purchase data.');
        check(is_int($payload['deliveryDate']) && $payload['deliveryDate'] > 1000000000000 && $first['deliveryDate'] === $payload['deliveryDate'], 'Delivery date must be milliseconds and remain stable across retry.');
        $calls = count(Fixture::$calls); payment_deliver(['orderId' => $data['order']]);
        check(count(Fixture::$calls) === $calls, 'Reported delivery was sent again.');
    };

    $failed = 0;
    foreach ($tests as $label => $test) {
        try {
            resetFixture(); \db()->beginTransaction(); $test();
            echo 'PASS ', $label, "\n";
        } catch (Throwable $error) {
            ++$failed;
            // Harness assertion text is controlled. Never print provider data or private SQL errors.
            $message = $error instanceof \PDOException ? 'Disposable database fixture failed.' : $error->getMessage();
            fwrite(STDERR, 'FAIL ' . $label . ': ' . $message . "\n");
        } finally {
            if (\db()->inTransaction()) \db()->rollBack();
        }
    }
    echo count($tests) - $failed, '/', count($tests), " DigiPay fixture checks passed. No external provider requests were sent.\n";
    exit($failed === 0 ? 0 : 1);
}
