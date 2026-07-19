<?php

declare(strict_types=1);
use App\Services\Plaid\PlaidService;

it('gets plaid status', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_STATUS]);
    $response = $plaid->getAPIStatus();

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ]);

    expect($response['page']['name'])
        ->toBe('Plaid');
});
