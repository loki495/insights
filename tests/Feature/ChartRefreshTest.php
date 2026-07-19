<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForChartRefreshTest(): Account
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

it('does not refresh the chart on a pure pagination action', function (): void {
    $account = makeAccountForChartRefreshTest();

    Transaction::factory(30)->for($account)->create(['name' => 'Txn', 'amount' => -10, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('nextPage');

    $test->assertNotDispatched('refresh-chart');
});

it('refreshes the chart when a filter property changes', function (): void {
    $account = makeAccountForChartRefreshTest();
    Transaction::factory()->for($account)->create(['name' => 'Coffee', 'amount' => -5, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('search', 'Coffee');

    $test->assertDispatched('refresh-chart');
});

it('does not refresh the chart merely from toggling selected_transactions', function (): void {
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn->id]);

    $test->assertNotDispatched('refresh-chart');
});

it('refreshes the chart after saveCategory', function (): void {
    $category = Category::create(['name' => 'Groceries']);
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('saveCategory', $txn->id, $category->id);

    $test->assertDispatched('refresh-chart');
});

it('refreshes the chart after clearCategory', function (): void {
    $category = Category::create(['name' => 'Groceries']);
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);
    $txn->categories()->sync([$category->id]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('clearCategory', $txn->id);

    $test->assertDispatched('refresh-chart');
});

it('refreshes the chart after bulkAssignCategory', function (): void {
    $category = Category::create(['name' => 'Groceries']);
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn->id]);
    $test->call('bulkAssignCategory', $category->id);

    $test->assertDispatched('refresh-chart');
});

it('refreshes the chart after bulkDeleteTransactions', function (): void {
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create([
        'name' => 'Txn', 'amount' => -5, 'currency' => 'USD', 'original' => ['manual' => true],
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn->id]);
    $test->call('bulkDeleteTransactions');

    $test->assertDispatched('refresh-chart');
});

it('refreshes the chart when drilling into a category via the chart click event', function (): void {
    $parent = Category::create(['name' => 'Expenses']);
    $child = Category::create(['name' => 'Bars', 'parent_id' => $parent->id]);
    $account = makeAccountForChartRefreshTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);
    $txn->categories()->sync([$child->id]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->dispatch('chart-clicked', $parent->id);

    $test->assertDispatched('refresh-chart');
});

it('refreshes the chart after the transactions-updated event (Pull Data)', function (): void {
    $account = makeAccountForChartRefreshTest();
    Transaction::factory()->for($account)->create(['name' => 'Txn', 'amount' => -5, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->dispatch('transactions-updated');

    $test->assertDispatched('refresh-chart');
});
