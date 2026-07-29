<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\User;

it('has valid toArray response', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_123',
        'provider_name' => 'Test Bank',
        'access_token' => 'access_123',
    ]);

    expect($linkedAccount->toArray())->toHaveKeys([
        'id',
        'provider_name',
        'item_id',
        'user_id',
    ]);
});
