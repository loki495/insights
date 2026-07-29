<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function transactionFor(User $user): Transaction
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);

    return Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -10, 'currency' => 'USD']);
}

it('lets a user view/update/delete a transaction on their own account', function (): void {
    $user = User::factory()->create();
    $transaction = transactionFor($user);

    expect($user->can('view', $transaction))->toBeTrue()
        ->and($user->can('update', $transaction))->toBeTrue()
        ->and($user->can('delete', $transaction))->toBeTrue();
});

it('prevents a user from viewing/updating/deleting a transaction on another user\'s account', function (): void {
    $owner = User::factory()->create();
    $transaction = transactionFor($owner);

    $stranger = User::factory()->create();

    expect($stranger->can('view', $transaction))->toBeFalse()
        ->and($stranger->can('update', $transaction))->toBeFalse()
        ->and($stranger->can('delete', $transaction))->toBeFalse();
});

it('lets any authenticated user create or view-any transactions', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', Transaction::class))->toBeTrue()
        ->and($user->can('viewAny', Transaction::class))->toBeTrue();
});
