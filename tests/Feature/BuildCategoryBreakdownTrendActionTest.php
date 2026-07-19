<?php

declare(strict_types=1);

use App\Actions\Reports\BuildCategoryBreakdownTrendAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

function makeAccountForCategoryBreakdownTrendTest(): Account
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

it('sums each selected category as a magnitude, per period', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();

    $groceries = Category::create(['name' => 'Groceries', 'color' => '#10b981']);
    $eatingOut = Category::create(['name' => 'Eating out', 'color' => '#ef4444']);

    $g1 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -100, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $g1->categories()->sync([$groceries->id]);

    $e1 = Transaction::factory()->for($account)->create(['name' => 'Restaurant', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-15', 'type' => 'expense']);
    $e1->categories()->sync([$eatingOut->id]);

    $g2 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -120, 'currency' => 'USD', 'created_at' => '2026-02-05', 'type' => 'expense']);
    $g2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-02-28'),
        'monthly',
        [$groceries->id, $eatingOut->id],
    );

    expect($result['periods'])->toBe(['Jan 2026', 'Feb 2026']);

    $groceriesSeries = collect($result['series'])->firstWhere('category_id', $groceries->id);
    $eatingOutSeries = collect($result['series'])->firstWhere('category_id', $eatingOut->id);

    expect($groceriesSeries['label'])->toBe('Groceries');
    expect($groceriesSeries['color'])->toBe('#10b981');
    expect($groceriesSeries['values'])->toBe([100.0, 120.0]);

    expect($eatingOutSeries['values'])->toBe([50.0, 0.0]);
});

it('includes descendants of a selected parent category', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();

    $parent = Category::create(['name' => 'Expenses']);
    $child = Category::create(['name' => 'Groceries', 'parent_id' => $parent->id]);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -75, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $transaction->categories()->sync([$child->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$parent->id],
    );

    expect($result['series'][0]['values'])->toBe([75.0]);
});

it('lets a transaction contribute to more than one selected category', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();

    $groceries = Category::create(['name' => 'Groceries']);
    $household = Category::create(['name' => 'Household']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Costco', 'amount' => -200, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $transaction->categories()->sync([$groceries->id, $household->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$groceries->id, $household->id],
    );

    foreach ($result['series'] as $series) {
        expect($series['values'])->toBe([200.0]);
    }
});

it('excludes transfers and adjustments even if categorized', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();
    $transfers = Category::create(['name' => 'Transfers']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -500, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'transfer']);
    $transaction->categories()->sync([$transfers->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$transfers->id],
    );

    expect($result['series'][0]['values'])->toBe([0.0]);
});

it('skips category ids that do not exist', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [999999],
    );

    expect($result['series'])->toBe([]);
});

it('falls back to a default color when the category has none set', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();
    $category = Category::create(['name' => 'Misc', 'color' => null]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$category->id],
    );

    expect($result['series'][0]['color'])->toBe('#3b82f6');
});

it('groups into daily periods', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();
    $groceries = Category::create(['name' => 'Groceries']);

    $t1 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-05', 'type' => 'expense']);
    $t1->categories()->sync([$groceries->id]);

    $t2 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -30, 'currency' => 'USD', 'created_at' => '2026-01-06', 'type' => 'expense']);
    $t2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-05'),
        Carbon::parse('2026-01-06'),
        'daily',
        [$groceries->id],
    );

    expect($result['periods'])->toBe(['Jan 5, 2026', 'Jan 6, 2026']);
    expect($result['series'][0]['values'])->toBe([50.0, 30.0]);
});

it('rejects an invalid granularity', function (): void {
    $account = makeAccountForCategoryBreakdownTrendTest();
    $category = Category::create(['name' => 'Groceries']);

    expect(fn () => BuildCategoryBreakdownTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'weekly',
        [$category->id],
    ))->toThrow(InvalidArgumentException::class);
});
