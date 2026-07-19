<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForTypeFilterTest(): Account
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

it('shows every type by default when no type filter is selected', function (): void {
    $account = makeAccountForTypeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 1000, 'currency' => 'USD', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'To Savings', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(2);
});

it('filters down to just the selected types', function (): void {
    $account = makeAccountForTypeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 1000, 'currency' => 'USD', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'To Savings', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('type_filters', ['transfer']);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(1);
    expect($test->instance()->getTransactionsQuery()->first()->name)->toBe('To Savings');
});
