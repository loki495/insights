<?php

declare(strict_types=1);

it('calls existing service endpoint', function (): void {
    $plaid = app(\App\Services\Plaid\PlaidService::class);

    $response = $plaid->getAPIStatus();

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ]);

    expect($response['page']['name'])
        ->toBe('Plaid');

});

it('fails if endpoint class does not exist', function (): void {
    $plaid = app(\App\Services\Plaid\PlaidService::class);

    $plaid->getNonExistentEndpoint();

})->throws(\Exception::class, 'Unknown endpoint: getNonExistentEndpoint');
