<?php

declare(strict_types=1);

use App\Actions\ReconcileLinkedAccountTransactions;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('does not fatal on an account with zero transactions', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 100,
    ]);

    ReconcileLinkedAccountTransactions::run($linkedAccount->fresh());

    expect(true)->toBeTrue();
});

it('sets each transaction\'s running_balance by walking backwards from the current balance', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 80,
    ]);

    $older = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD', 'created_at' => now()->subDay()]);
    $newer = Transaction::factory()->for($account)->create(['name' => 'Cafe', 'amount' => -10, 'currency' => 'USD', 'created_at' => now()]);

    ReconcileLinkedAccountTransactions::run($linkedAccount->fresh());

    expect($newer->fresh()->running_balance)->toBe(80);
    expect($older->fresh()->running_balance)->toBe(90);
});
