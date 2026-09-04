<?php

declare(strict_types=1);

it('calls existing service endpoint', function (): void {
    $plaid = plaid('status');

    $response = fetchPlaidStatusWithRetry($plaid);

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ])
        ->and($response['page']['name'])->toBe('Plaid');

});

it('fails if endpoint class does not exist', function (): void {
    $plaid = plaid('sandbox');

    $plaid->getNonExistentEndpoint();

})->throws(Exception::class, 'Unknown endpoint: getNonExistentEndpoint');
