<?php

declare(strict_types=1);

use App\Services\Curl\API;
use App\Services\Curl\CurlRequestException;
use App\Services\Curl\Request;

beforeEach(fn () => fakeCurl());

afterEach(fn () => stopFakingCurl());

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
