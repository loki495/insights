<?php

declare(strict_types=1);
use App\Services\Curl\CurlRequestException;
use App\Services\Plaid\PlaidService;

beforeEach(fn () => fakeCurl(plaidStatusResponse()));

afterEach(fn () => stopFakingCurl());

it('gets plaid status', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_STATUS]);

    $response = $plaid->getAPIStatus();

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ])
        ->and($response['page']['name'])->toBe('Plaid')
        ->and($GLOBALS['__curlMockSetopts'][CURLOPT_URL])->toBe('https://status.plaid.com/api/v2/status.json')
        ->and($GLOBALS['__curlMockSetopts'][CURLOPT_CUSTOMREQUEST])->toBe('GET');
});

it('reports a status service failure', function (): void {
    $GLOBALS['__curlMockHttpCode'] = 503;
    $GLOBALS['__curlMockResponse'] = '{"message":"Service Unavailable"}';

    expect(fn () => plaid('status')->getAPIStatus())
        ->toThrow(CurlRequestException::class, 'HTTP 503 error');
});

it('reports malformed status JSON', function (): void {
    $GLOBALS['__curlMockResponse'] = 'not json';

    expect(fn () => plaid('status')->getAPIStatus())
        ->toThrow(RuntimeException::class, 'Json error:');
});

it('reports a status connection failure', function (): void {
    $GLOBALS['__curlMockResponse'] = false;
    $GLOBALS['__curlMockErrno'] = 7;
    $GLOBALS['__curlMockError'] = 'Could not connect';

    expect(fn () => plaid('status')->getAPIStatus())
        ->toThrow(RuntimeException::class, 'Curl error: Could not connect');
});
