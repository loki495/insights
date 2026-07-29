<?php

declare(strict_types=1);

use App\Actions\Reports\BuildCategoryBreakdownTrendAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/**
 * @return array{0: User, 1: Account}
 */
function makeAccountForCategoryBreakdownTrendTest(): array
{
    $user = User::factory()->create();
    test()->actingAs($user);

    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Account',
        'official_name' => 'Account Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);

    return [$user, $account];
}

it('sums each selected category as a magnitude, per period', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();

    $groceries = categoryFor($user, 'Groceries', color: '#10b981');
    $eatingOut = categoryFor($user, 'Eating out', color: '#ef4444');

    $g1 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -100, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $g1->categories()->sync([$groceries->id]);

    $e1 = Transaction::factory()->for($account)->create(['name' => 'Restaurant', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-15', 'type' => 'expense']);
    $e1->categories()->sync([$eatingOut->id]);

    $g2 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -120, 'currency' => 'USD', 'created_at' => '2026-02-05', 'type' => 'expense']);
    $g2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-02-28'),
        'monthly',
        [$groceries->id, $eatingOut->id],
    );

    expect($result['periods'])->toBe(['Jan 2026', 'Feb 2026']);

    $groceriesSeries = collect($result['series'])->firstWhere('category_id', $groceries->id);
    $eatingOutSeries = collect($result['series'])->firstWhere('category_id', $eatingOut->id);
    expect($groceriesSeries)->toMatchArray(['label' => 'Groceries', 'color' => '#10b981', 'values' => [100.0, 120.0]])
        ->and($eatingOutSeries['values'])->toBe([50.0, 0.0]);
});

it('includes descendants of a selected parent category', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();

    $parent = categoryFor($user, 'Expenses');
    $child = categoryFor($user, 'Groceries', $parent->id);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -75, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $transaction->categories()->sync([$child->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$parent->id],
    );

    expect($result['series'][0]['values'])->toBe([75.0]);
});

it('lets a transaction contribute to more than one selected category', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();

    $groceries = categoryFor($user, 'Groceries');
    $household = categoryFor($user, 'Household');

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Costco', 'amount' => -200, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $transaction->categories()->sync([$groceries->id, $household->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
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
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $transfers = categoryFor($user, 'Transfers');

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -500, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'transfer']);
    $transaction->categories()->sync([$transfers->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$transfers->id],
    );

    expect($result['series'][0]['values'])->toBe([0.0]);
});

it('skips category ids that do not exist', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [999999],
    );

    expect($result['series'])->toBeEmpty();
});

it('falls back to a default color when the category has none set', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $category = categoryFor($user, 'Misc');

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$category->id],
    );

    expect($result['series'][0]['color'])->toBe('#3b82f6');
});

it('groups into daily periods', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $groceries = categoryFor($user, 'Groceries');

    $t1 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-05', 'type' => 'expense']);
    $t1->categories()->sync([$groceries->id]);

    $t2 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -30, 'currency' => 'USD', 'created_at' => '2026-01-06', 'type' => 'expense']);
    $t2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-05'),
        Carbon::parse('2026-01-06'),
        'daily',
        [$groceries->id],
    );

    expect($result['periods'])->toBe(['Jan 5, 2026', 'Jan 6, 2026'])
        ->and($result['series'][0]['values'])->toBe([50.0, 30.0]);
});

it('filters by a simple search term against name or merchant_name', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $groceries = categoryFor($user, 'Groceries');

    $t1 = Transaction::factory()->for($account)->create(['name' => 'Whole Foods Market', 'merchant_name' => 'Whole Foods', 'amount' => -50, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $t1->categories()->sync([$groceries->id]);
    $t2 = Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -30, 'currency' => 'USD', 'created_at' => '2026-01-11', 'type' => 'expense']);
    $t2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$groceries->id],
        'whole foods',
    );

    expect($result['series'][0]['values'])->toBe([50.0]);
});

it('filters by an amount range regardless of sign', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $groceries = categoryFor($user, 'Groceries');

    $t1 = Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'type' => 'expense']);
    $t1->categories()->sync([$groceries->id]);
    $t2 = Transaction::factory()->for($account)->create(['name' => 'In range', 'amount' => -75, 'currency' => 'USD', 'created_at' => '2026-01-11', 'type' => 'expense']);
    $t2->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
        [$groceries->id],
        '',
        '50',
        '100',
    );

    expect($result['series'][0]['values'])->toBe([75.0]);
});

it('rejects an invalid granularity', function (): void {
    [$user, $account] = makeAccountForCategoryBreakdownTrendTest();
    $category = categoryFor($user, 'Groceries');

    expect(fn (): array => BuildCategoryBreakdownTrendAction::run(
        $user,
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'weekly',
        [$category->id],
    ))->toThrow(InvalidArgumentException::class);
});
