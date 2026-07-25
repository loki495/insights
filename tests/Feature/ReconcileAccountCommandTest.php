<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('fails cleanly with a "not found" message for a nonexistent linked_account_id instead of fataling', function (): void {
    $this->artisan('transactions:reconcile', ['linked_account_id' => 999999])
        ->expectsOutputToContain('Linked account 999999 not found.')
        ->assertExitCode(1);
});

it('reconciles the given linked account\'s transactions', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 80,
    ]);
    $txn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);

    $this->artisan('transactions:reconcile', ['linked_account_id' => $linkedAccount->id])
        ->assertExitCode(0);

    expect($txn->fresh()->running_balance)->toBe(80);
});
