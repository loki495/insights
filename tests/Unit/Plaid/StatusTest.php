<?php

declare(strict_types=1);

it('gets plaid status', function (): void {
    $plaid = app(\App\Services\Plaid\PlaidService::class, ['environment' => \App\Services\Plaid\PlaidService::ENV_STATUS]);
    $response = $plaid->getAPIStatus();

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ]);

    expect($response['page']['name'])
        ->toBe('Plaid');
});
