<?php

declare(strict_types=1);

beforeEach(fn () => fakeCurl(plaidStatusResponse()));

afterEach(fn () => stopFakingCurl());

it('calls existing service endpoint', function (): void {
    $plaid = plaid('status');

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

it('fails if endpoint class does not exist', function (): void {
    $plaid = plaid('sandbox');

    $plaid->getNonExistentEndpoint();

})->throws(Exception::class, 'Unknown endpoint: getNonExistentEndpoint');
