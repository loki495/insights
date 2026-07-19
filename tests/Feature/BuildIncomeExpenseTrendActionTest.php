<?php

declare(strict_types=1);

use App\Actions\Reports\BuildIncomeExpenseTrendAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

function makeAccountForIncomeExpenseTrendTest(): Account
{
    $user = User::factory()->create();

    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Account',
        'official_name' => 'Account Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);
}

it('buckets income and expense into monthly periods', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -100, 'currency' => 'USD', 'created_at' => '2026-01-15', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => '2026-02-10', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Rent', 'amount' => -800, 'currency' => 'USD', 'created_at' => '2026-02-12', 'type' => 'expense']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-02-28'),
        'monthly',
    );

    expect($result['periods'])->toBe(['Jan 2026', 'Feb 2026']);
    expect($result['income'])->toBe([2000.0, 2000.0]);
    expect($result['expense'])->toBe([100.0, 800.0]);
    expect($result['net'])->toBe([1900.0, 1200.0]);
});

it('excludes transfers and adjustments from the totals', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();
    $otherAccount = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -500, 'currency' => 'USD', 'created_at' => '2026-01-12', 'type' => 'transfer']);
    Transaction::factory()->for($account)->create(['name' => 'Correction', 'amount' => 50, 'currency' => 'USD', 'created_at' => '2026-01-14', 'type' => 'adjustment']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
    );

    expect($result['income'])->toBe([2000.0]);
    expect($result['expense'])->toBe([0.0]);
});

it('only includes transactions from the given accounts', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();
    $otherAccount = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'Mine', 'amount' => 100, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'income']);
    Transaction::factory()->for($otherAccount)->create(['name' => 'Not Mine', 'amount' => 5000, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'income']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
    );

    expect($result['income'])->toBe([100.0]);
});

it('groups into quarterly and yearly periods', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => 100, 'currency' => 'USD', 'created_at' => '2026-02-10', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => 100, 'currency' => 'USD', 'created_at' => '2026-05-10', 'type' => 'income']);

    $quarterly = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-06-30'),
        'quarterly',
    );

    expect($quarterly['periods'])->toBe(['Q1 2026', 'Q2 2026']);
    expect($quarterly['income'])->toBe([100.0, 100.0]);

    $yearly = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-12-31'),
        'yearly',
    );

    expect($yearly['periods'])->toBe(['2026']);
    expect($yearly['income'])->toBe([200.0]);
});

it('rejects an invalid granularity', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    expect(fn () => BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'weekly',
    ))->toThrow(InvalidArgumentException::class);
});
