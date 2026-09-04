<?php

declare(strict_types=1);

namespace App\Services\Curl {
    /**
     * Test-only curl_* overrides. Nothing else in the app calls curl_* from within this
     * namespace, and PHP resolves an unqualified function call by checking the current namespace
     * before falling back to the global one — so these transparently intercept every curl_* call
     * Request::makeRequest() (and, transitively, API::__call()) makes, without a real
     * network/curl round trip or a mocking library. State is read from $GLOBALS so each test can
     * configure its own fake response via the helpers below.
     *
     * Once declared, these functions exist for the rest of the PHP process — PHP has no way to
     * "undeclare" them — so without the __curlMockActive guard below, any *other* test that
     * happens to run afterward in the same process/worker and calls real curl_* from this same
     * namespace (e.g. a real Plaid network call in tests/Unit/Plaid/StatusTest.php or
     * tests/Unit/ServicesTest.php) would silently get these fakes instead, regardless of which
     * test file that call is in. Confirmed reproducible: running this file's tests alongside
     * those real-network tests in the same run made the real ones always receive a stale '{}'.
     * Guarding on a flag that's only true during this file's own beforeEach/afterEach — and
     * delegating to the real global curl_* otherwise — keeps the fakes scoped to this file.
     */
    function curl_init(): object
    {
        return ($GLOBALS['__curlMockActive'] ?? false) ? new \stdClass : \curl_init();
    }

    function curl_setopt(object $ch, int $option, mixed $value): bool
    {
        if (! ($GLOBALS['__curlMockActive'] ?? false)) {
            return \curl_setopt($ch, $option, $value);
        }

        $GLOBALS['__curlMockSetopts'][$option] = $value;

        return true;
    }

    function curl_exec(object $ch): string|false
    {
        if (! ($GLOBALS['__curlMockActive'] ?? false)) {
            return \curl_exec($ch);
        }

        return $GLOBALS['__curlMockResponse'] ?? '{}';
    }

    function curl_errno(object $ch): int
    {
        if (! ($GLOBALS['__curlMockActive'] ?? false)) {
            return \curl_errno($ch);
        }

        return $GLOBALS['__curlMockErrno'] ?? 0;
    }

    function curl_error(object $ch): string
    {
        if (! ($GLOBALS['__curlMockActive'] ?? false)) {
            return \curl_error($ch);
        }

        return $GLOBALS['__curlMockError'] ?? '';
    }

    function curl_getinfo(object $ch, int $option): mixed
    {
        if (! ($GLOBALS['__curlMockActive'] ?? false)) {
            return \curl_getinfo($ch, $option);
        }

        return $GLOBALS['__curlMockHttpCode'] ?? 200;
    }

    function curl_close(object $ch): void {}
}

namespace {

    use App\Services\Curl\API;
    use App\Services\Curl\CurlRequestException;
    use App\Services\Curl\Request;

    beforeEach(function (): void {
        $GLOBALS['__curlMockActive'] = true;
        $GLOBALS['__curlMockSetopts'] = [];
        $GLOBALS['__curlMockResponse'] = '{}';
        $GLOBALS['__curlMockErrno'] = 0;
        $GLOBALS['__curlMockError'] = '';
        $GLOBALS['__curlMockHttpCode'] = 200;
    });

    afterEach(function (): void {
        unset(
            $GLOBALS['__curlMockActive'],
            $GLOBALS['__curlMockSetopts'],
            $GLOBALS['__curlMockResponse'],
            $GLOBALS['__curlMockErrno'],
            $GLOBALS['__curlMockError'],
            $GLOBALS['__curlMockHttpCode'],
        );
    });

    it('toArray/toJson expose the request\'s url, method, headers, and data', function (): void {
        $request = new Request('https://example.test/thing', 'POST')
            ->addHeader('X-Api-Key', 'secret')
            ->addDataItem('foo', 'bar');

        $expected = [
            'url' => 'https://example.test/thing',
            'method' => 'POST',
            'headers' => ['X-Api-Key' => 'secret'],
            'data' => ['foo' => 'bar'],
        ];

        expect($request->toArray())->toBe($expected)
            ->and($request->toJson())->toBe(json_encode($expected));
    });

    it('returns the decoded response body on a successful call', function (): void {
        $GLOBALS['__curlMockResponse'] = json_encode(['ok' => true]);

        $result = new Request('https://example.test/status', 'GET')->makeRequest();

        expect($result)->toBe(['ok' => true]);
    });

    it('json-encodes POST data when Content-Type is application/json', function (): void {
        new Request('https://example.test/thing', 'POST')
            ->addHeader('Content-Type', 'application/json')
            ->addDataItem('foo', 'bar')
            ->makeRequest();

        expect($GLOBALS['__curlMockSetopts'][CURLOPT_POST])->toBeTrue()
            ->and($GLOBALS['__curlMockSetopts'][CURLOPT_POSTFIELDS])->toBe(json_encode(['foo' => 'bar']));
    });

    it('does not set POSTFIELDS for a JSON POST request with no data', function (): void {
        new Request('https://example.test/thing', 'POST')
            ->addHeader('Content-Type', 'application/json')
            ->makeRequest();

        expect($GLOBALS['__curlMockSetopts'])->not->toHaveKey(CURLOPT_POSTFIELDS);
    });

    it('sends POST data as raw fields when Content-Type is not JSON', function (): void {
        new Request('https://example.test/thing', 'POST')
            ->addDataItem('foo', 'bar')
            ->makeRequest();

        expect($GLOBALS['__curlMockSetopts'][CURLOPT_POSTFIELDS])->toBe(['foo' => 'bar']);
    });

    it('uses CUSTOMREQUEST and a query-string body for a non-POST method', function (): void {
        new Request('https://example.test/thing', 'DELETE')
            ->addDataItem('id', '5')
            ->makeRequest();

        expect($GLOBALS['__curlMockSetopts'][CURLOPT_CUSTOMREQUEST])->toBe('DELETE')
            ->and($GLOBALS['__curlMockSetopts'][CURLOPT_POSTFIELDS])->toBe(http_build_query(['id' => '5']));
    });

    it('sends every added header formatted for CURLOPT_HTTPHEADER', function (): void {
        new Request('https://example.test/thing', 'GET')
            ->addHeader('X-Api-Key', 'secret')
            ->makeRequest();

        expect($GLOBALS['__curlMockSetopts'][CURLOPT_HTTPHEADER])->toBe(['X-Api-Key: secret']);
    });

    it('throws when curl reports a transport-level error', function (): void {
        $GLOBALS['__curlMockErrno'] = 7;
        $GLOBALS['__curlMockError'] = 'Could not connect';

        new Request('https://example.test/thing', 'GET')->makeRequest();
    })->throws(RuntimeException::class, 'Curl error: Could not connect');

    it('throws when curl_exec itself returns false', function (): void {
        $GLOBALS['__curlMockResponse'] = false;

        new Request('https://example.test/thing', 'GET')->makeRequest();
    })->throws(RuntimeException::class, 'Curl error: ');

    it('propagates a Plaid-shaped error response through parseResponse', function (): void {
        $GLOBALS['__curlMockResponse'] = json_encode(['error_type' => 'ITEM_ERROR', 'error_code' => 'X']);

        new Request('https://example.test/thing', 'GET')->makeRequest();
    })->throws(CurlRequestException::class);

    it('API::__call forwards data items through to the real request', function (): void {
        $GLOBALS['__curlMockResponse'] = json_encode(['status' => 'ok']);

        $api = new API('plaid', 'https://sandbox.plaid.com/');
        // @phpstan-ignore method.notFound (API's endpoints are dispatched dynamically via __call)
        $result = $api->getAPIStatus(data: ['foo' => 'bar']);

        // @phpstan-ignore argument.templateType (same __call dynamic-dispatch limitation as above)
        expect($result)->toBe(['status' => 'ok'])
            ->and($GLOBALS['__curlMockSetopts'][CURLOPT_POSTFIELDS])->toBe(http_build_query(['foo' => 'bar']));
    });

    it('API::__call throws for an unknown endpoint', function (): void {
        // @phpstan-ignore method.notFound (API's endpoints are dispatched dynamically via __call)
        new API('plaid', 'https://sandbox.plaid.com/')->notARealEndpoint();
    })->throws(Exception::class, 'Unknown endpoint: notARealEndpoint');

}
