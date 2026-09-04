<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForTotalTest(): Account
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

function makeTransactionForTotalTest(Account $account, float|int $amount): Transaction
{
    return Transaction::factory()->for($account)->create([
        'name' => 'Txn',
        'amount' => $amount,
        'currency' => 'USD',
        'type' => 'expense',
    ]);
}

/**
 * Regression: `amount` is stored as integer cents via MoneyCast, but Eloquent lists
 * aggregates in Builder::$passthru, so `sum('amount')` runs on the base query builder
 * and never applies the cast. Before the fix it returned raw cents, rendering a $105.27
 * total as $10,527.00 on the main transactions screen.
 */
it('reports the total in dollars, not raw integer cents', function (): void {
    $account = makeAccountForTotalTest();
    makeTransactionForTotalTest($account, -10.50);
    makeTransactionForTotalTest($account, -20.25);
    makeTransactionForTotalTest($account, -74.52);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertViewHas('total', fn ($total): bool => abs((float) $total - (-105.27)) < 0.0001);
});

it('keeps cents precision rather than truncating to whole dollars', function (): void {
    $account = makeAccountForTotalTest();
    makeTransactionForTotalTest($account, -0.01);
    makeTransactionForTotalTest($account, -0.02);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertViewHas('total', fn ($total): bool => abs((float) $total - (-0.03)) < 0.0001);
});

it('nets positive and negative amounts against each other', function (): void {
    $account = makeAccountForTotalTest();
    makeTransactionForTotalTest($account, 100.00);
    makeTransactionForTotalTest($account, -40.75);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertViewHas('total', fn ($total): bool => abs((float) $total - 59.25) < 0.0001);
});

it('totals every matching row, not just the rows on the current page', function (): void {
    $account = makeAccountForTotalTest();
    // 30 rows at a 25-row default page size: a total computed from the paginated
    // collection instead of the query would come back as -25.00, not -30.00.
    Transaction::factory()->for($account)->count(30)->create([
        'name' => 'Txn',
        'amount' => -1.00,
        'currency' => 'USD',
        'type' => 'expense',
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertViewHas('total', fn ($total): bool => abs((float) $total - (-30.00)) < 0.0001);
});

it('reports a zero total when nothing matches, rather than null or an error', function (): void {
    $account = makeAccountForTotalTest();

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertOk();
    $test->assertViewHas('total', fn ($total): bool => $total !== null && abs((float) $total) < 0.0001);
});
