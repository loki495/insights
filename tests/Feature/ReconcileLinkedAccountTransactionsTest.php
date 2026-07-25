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

it('reconciles each account under the same linked account independently', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $checking = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 50,
    ]);
    $savings = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '1111', 'name' => 'Savings', 'official_name' => 'Savings Official',
        'type' => 'depository', 'subtype' => 'savings', 'current_balance' => 1000,
    ]);

    $checkingTxn = Transaction::factory()->for($checking)->create(['name' => 'Store', 'amount' => -10, 'currency' => 'USD']);
    $savingsTxn = Transaction::factory()->for($savings)->create(['name' => 'Interest', 'amount' => 5, 'currency' => 'USD']);

    ReconcileLinkedAccountTransactions::run($linkedAccount->fresh());

    expect($checkingTxn->fresh()->running_balance)->toBe(50);
    expect($savingsTxn->fresh()->running_balance)->toBe(1000);
});

it('stops walking once a transaction\'s running_balance already matches, leaving older ones untouched', function (): void {
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

    // Already correct for the newest transaction; the older one is deliberately left wrong
    // (999) to prove the early-exit break really does skip it rather than recomputing.
    $newer->running_balance = 80;
    $newer->save();
    $older->running_balance = 999;
    $older->save();

    ReconcileLinkedAccountTransactions::run($linkedAccount->fresh());

    expect($newer->fresh()->running_balance)->toBe(80);
    expect($older->fresh()->running_balance)->toBe(999);
});

it('with force=true, re-walks and recomputes every transaction even when the newest already matches', function (): void {
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

    $newer->running_balance = 80;
    $newer->save();
    $older->running_balance = 999;
    $older->save();

    ReconcileLinkedAccountTransactions::run($linkedAccount->fresh(), force: true);

    expect($newer->fresh()->running_balance)->toBe(80);
    expect($older->fresh()->running_balance)->toBe(90);
});
