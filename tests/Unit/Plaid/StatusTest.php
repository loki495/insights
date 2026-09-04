<?php

declare(strict_types=1);
use App\Services\Plaid\PlaidService;

it('gets plaid status', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_STATUS]);

    $response = fetchPlaidStatusWithRetry($plaid);

    expect($response)
        ->toHaveKeys([
            'status.description',
            'page.name',
        ])
        ->and($response['page']['name'])->toBe('Plaid');
});
