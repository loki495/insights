<?php

declare(strict_types=1);

it('has valid toArray response', function (): void {
    $linkedAccount = LinkedAccount::factory()->create();

    expect($linkedAccount->toArray())->toBeArray();
    expect($linkedAccount->toArray())->toHaveKeys([
        'id',
        'provider',
        'provider_id',
        'user_id',
    ]);
})->skip();
