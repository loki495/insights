<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function dashboardAccount(User $user, array $overrides = []): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ], $overrides));
}

it('shows Net Cash as the sum of tracked accounts only, excluding reference/excluded accounts', function (): void {
    $user = User::factory()->create();
    dashboardAccount($user, ['tracking_mode' => 'tracked', 'current_balance' => 100]);
    dashboardAccount($user, ['tracking_mode' => 'reference', 'current_balance' => 5000]);
    dashboardAccount($user, ['tracking_mode' => 'excluded', 'current_balance' => 9000]);

    test()->actingAs($user);
    $test = Livewire::test('admin.dashboard');

    $test->assertViewHas('netTotal', 100.0);
});

it('treats credit/loan accounts as liabilities subtracted from Net Cash', function (): void {
    $user = User::factory()->create();
    dashboardAccount($user, ['tracking_mode' => 'tracked', 'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 500]);
    dashboardAccount($user, ['tracking_mode' => 'tracked', 'type' => 'credit', 'subtype' => 'credit card', 'current_balance' => 200]);

    test()->actingAs($user);
    $test = Livewire::test('admin.dashboard');

    $test->assertSee('$300.00');
});

it('populates the net cash trend chart series for tracked accounts', function (): void {
    $user = User::factory()->create();
    $account = dashboardAccount($user, ['tracking_mode' => 'tracked', 'current_balance' => 100]);
    Transaction::factory()->for($account)->create([
        'name' => 'Deposit', 'amount' => 100, 'currency' => 'USD', 'running_balance' => 100,
    ]);

    test()->actingAs($user);
    $test = Livewire::test('admin.dashboard');

    expect($test->get('chart_series'))->not->toBeEmpty()
        ->and($test->get('chart_series')[0]['label'])->toBe('Net Cash')
        ->and($test->get('chart_periods'))->not->toBeEmpty();
});

it('excludes transfers and income from the "Spending This Month" snapshot', function (): void {
    $user = User::factory()->create();
    $account = dashboardAccount($user, ['tracking_mode' => 'tracked']);
    $groceries = Category::create(['name' => 'Groceries']);
    $transferCategory = Category::create(['name' => 'Transfers']);

    $expense = Transaction::factory()->for($account)->create([
        'name' => 'Store', 'amount' => -40, 'currency' => 'USD', 'type' => 'expense',
    ]);
    $expense->categories()->sync([$groceries->id]);

    $income = Transaction::factory()->for($account)->create([
        'name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'type' => 'income',
    ]);
    $income->categories()->sync([$groceries->id]);

    $transfer = Transaction::factory()->for($account)->create([
        'name' => 'Card payment', 'amount' => -300, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $transfer->categories()->sync([$transferCategory->id]);

    test()->actingAs($user);
    $test = Livewire::test('admin.dashboard');

    expect($test->get('chart_labels'))->toBe(['Groceries'])
        ->and($test->get('chart_values'))->toBe([40.0]);
});

it('only counts this month\'s transactions in the spending snapshot, not last month\'s', function (): void {
    $user = User::factory()->create();
    $account = dashboardAccount($user, ['tracking_mode' => 'tracked']);
    $groceries = Category::create(['name' => 'Groceries']);

    $thisMonth = Transaction::factory()->for($account)->create([
        'name' => 'Store', 'amount' => -40, 'currency' => 'USD', 'type' => 'expense',
        'created_at' => now(),
    ]);
    $thisMonth->categories()->sync([$groceries->id]);

    $lastMonth = Transaction::factory()->for($account)->create([
        'name' => 'Old store run', 'amount' => -999, 'currency' => 'USD', 'type' => 'expense',
        'created_at' => now()->subMonthsNoOverflow(2),
    ]);
    $lastMonth->categories()->sync([$groceries->id]);

    test()->actingAs($user);
    $test = Livewire::test('admin.dashboard');

    expect($test->get('chart_values'))->toBe([40.0]);
});

it('lists the 8 most recent transactions across tracked accounts, most recent first', function (): void {
    $user = User::factory()->create();
    $account = dashboardAccount($user, ['tracking_mode' => 'tracked']);

    foreach (range(1, 10) as $i) {
        Transaction::factory()->for($account)->create([
            'name' => sprintf('Txn %02d', $i), 'amount' => -1, 'currency' => 'USD',
            'created_at' => now()->subDays(10 - $i),
        ]);
    }

    test()->actingAs($user);

    $response = test()->get('/');
    $response->assertSee('Txn 10');
    $response->assertSee('Txn 03');
    $response->assertDontSee('Txn 02');
    $response->assertDontSee('Txn 01');
});

it('shows the institution and account for each recent transaction', function (): void {
    $user = User::factory()->create();
    $account = dashboardAccount($user, [
        'tracking_mode' => 'tracked',
        'name' => 'Everyday Checking',
        'nickname' => 'Household Account',
    ]);
    $account->linked_account->update(['provider_name' => 'Example Credit Union']);

    Transaction::factory()->for($account)->create([
        'name' => 'Neighborhood Market',
        'amount' => -25,
        'currency' => 'USD',
    ]);

    test()->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSeeTextInOrder([
            'Neighborhood Market',
            'Example Credit Union',
            'Household Account',
        ]);
});

it('does not show transactions from reference/excluded accounts in Recent Transactions', function (): void {
    $user = User::factory()->create();
    $trackedAccount = dashboardAccount($user, ['tracking_mode' => 'tracked']);
    $excludedAccount = dashboardAccount($user, ['tracking_mode' => 'excluded']);

    Transaction::factory()->for($trackedAccount)->create(['name' => 'Tracked Txn', 'amount' => -1, 'currency' => 'USD']);
    Transaction::factory()->for($excludedAccount)->create(['name' => 'Excluded Txn', 'amount' => -1, 'currency' => 'USD']);

    test()->actingAs($user);
    $response = test()->get('/');

    $response->assertSee('Tracked Txn');
    $response->assertDontSee('Excluded Txn');
});
