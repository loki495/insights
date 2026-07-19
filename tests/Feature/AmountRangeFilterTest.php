<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForAmountRangeFilterTest(): Account
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

it('shows every transaction by default when no amount range is set', function (): void {
    $account = makeAccountForAmountRangeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -5, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Large', 'amount' => -500, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(2);
});

it('filters to transactions within the given min/max amount', function (): void {
    $account = makeAccountForAmountRangeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Too small', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'In range', 'amount' => -75, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Too big', 'amount' => -500, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('amount_min', '50')
        ->set('amount_max', '100');

    $results = $test->instance()->getTransactionsQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('In range');
});

it('filters by magnitude regardless of sign, so income and expense of the same size both match', function (): void {
    $account = makeAccountForAmountRangeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Expense', 'amount' => -100, 'currency' => 'USD', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'Income', 'amount' => 100, 'currency' => 'USD', 'type' => 'income']);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('amount_min', '90')
        ->set('amount_max', '110');

    expect($test->instance()->getTransactionsQuery()->count())->toBe(2);
});

it('applies only a minimum when no maximum is set', function (): void {
    $account = makeAccountForAmountRangeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Big', 'amount' => -1000, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('amount_min', '500');

    $results = $test->instance()->getTransactionsQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Big');
});

it('applies only a maximum when no minimum is set', function (): void {
    $account = makeAccountForAmountRangeFilterTest();
    Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Big', 'amount' => -1000, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('amount_max', '50');

    $results = $test->instance()->getTransactionsQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Small');
});
