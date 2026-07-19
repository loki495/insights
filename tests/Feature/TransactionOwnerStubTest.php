<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('defaults owner to null and accepts an explicit value', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);

    $unset = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD']);
    expect($unset->owner)->toBeNull();

    $withOwner = Transaction::factory()->for($account)->create(['name' => 'Rent', 'amount' => -1000, 'currency' => 'USD', 'owner' => 'household']);
    expect($withOwner->fresh()->owner)->toBe('household');
});
