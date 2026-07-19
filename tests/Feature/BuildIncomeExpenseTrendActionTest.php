<?php

declare(strict_types=1);

use App\Actions\Reports\BuildIncomeExpenseTrendAction;
use App\Models\Account;
use App\Models\Category;
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

it('groups into daily periods', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => 100, 'currency' => 'USD', 'created_at' => '2026-01-05', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => -20, 'currency' => 'USD', 'created_at' => '2026-01-06', 'type' => 'expense']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-05'),
        Carbon::parse('2026-01-06'),
        'daily',
    );

    expect($result['periods'])->toBe(['Jan 5, 2026', 'Jan 6, 2026']);
    expect($result['income'])->toBe([100.0, 0.0]);
    expect($result['expense'])->toBe([0.0, 20.0]);
});

it('filters to the given categories and their descendants', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    $parent = Category::create(['name' => 'Expenses']);
    $groceries = Category::create(['name' => 'Groceries', 'parent_id' => $parent->id]);
    $rent = Category::create(['name' => 'Rent']);

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'income']);

    $groceryTxn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -100, 'currency' => 'USD', 'created_at' => '2026-01-11', 'type' => 'expense']);
    $groceryTxn->categories()->sync([$groceries->id]);

    $rentTxn = Transaction::factory()->for($account)->create(['name' => 'Landlord', 'amount' => -800, 'currency' => 'USD', 'created_at' => '2026-01-12', 'type' => 'expense']);
    $rentTxn->categories()->sync([$rent->id]);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$parent->id],
    );

    // Only the Groceries-tagged expense (under $parent) counts; the untagged paycheck and the
    // Rent expense (a different, unselected category) both drop out.
    expect($result['income'])->toBe([0.0]);
    expect($result['expense'])->toBe([100.0]);
});

it('counts a transaction once even if it matches more than one selected category', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    $groceries = Category::create(['name' => 'Groceries']);
    $household = Category::create(['name' => 'Household']);

    $txn = Transaction::factory()->for($account)->create(['name' => 'Costco', 'amount' => -200, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $txn->categories()->sync([$groceries->id, $household->id]);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$groceries->id, $household->id],
    );

    expect($result['expense'])->toBe([200.0]);
});

it('rejects an invalid granularity', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    expect(fn (): array => BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'weekly',
    ))->toThrow(InvalidArgumentException::class);
});

it('filters by a simple search term against name or merchant_name', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'Whole Foods Market', 'merchant_name' => 'Whole Foods', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'Rent Payment', 'merchant_name' => 'Landlord', 'amount' => -800, 'currency' => 'USD', 'created_at' => '2026-01-11', 'type' => 'expense']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [],
        'whole foods',
    );

    expect($result['expense'])->toBe([50.0]);
});

it('filters by an amount range regardless of sign', function (): void {
    $account = makeAccountForIncomeExpenseTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'In range expense', 'amount' => -75, 'currency' => 'USD', 'created_at' => '2026-01-11', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'In range income', 'amount' => 75, 'currency' => 'USD', 'created_at' => '2026-01-12', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Too big', 'amount' => -500, 'currency' => 'USD', 'created_at' => '2026-01-13', 'type' => 'expense']);

    $result = BuildIncomeExpenseTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [],
        '',
        '50',
        '100',
    );

    expect($result['income'])->toBe([75.0]);
    expect($result['expense'])->toBe([75.0]);
});
