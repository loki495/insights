<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForPerPageTest(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);

    test()->actingAs($user);

    return $account;
}

it('defaults to 25 transactions per page', function (): void {
    $account = makeAccountForPerPageTest();
    Transaction::factory()->for($account)->count(30)->create(['name' => 'Txn', 'amount' => -10, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertViewHas('transactions', fn ($transactions): bool => $transactions->perPage() === 25 && $transactions->count() === 25);
});

it('changes the page size when per_page is updated', function (): void {
    $account = makeAccountForPerPageTest();
    Transaction::factory()->for($account)->count(30)->create(['name' => 'Txn', 'amount' => -10, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('per_page', 10);

    $test->assertViewHas('transactions', fn ($transactions): bool => $transactions->perPage() === 10 && $transactions->count() === 10);
});

it('resets to page 1 when per_page changes', function (): void {
    $account = makeAccountForPerPageTest();
    Transaction::factory()->for($account)->count(30)->create(['name' => 'Txn', 'amount' => -10, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('nextPage');
    $test->set('per_page', 10);

    $test->assertViewHas('transactions', fn ($transactions): bool => $transactions->currentPage() === 1);
});

it('clamps a tampered per_page value back to the default instead of trusting it', function (): void {
    $account = makeAccountForPerPageTest();
    Transaction::factory()->for($account)->count(5)->create(['name' => 'Txn', 'amount' => -10, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('per_page', 999999);

    $test->assertSet('per_page', 25);
});
