<?php

declare(strict_types=1);

use App\Actions\Reports\BuildBalanceTrendAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

function makeAccountForBalanceTrendTest(string $type = 'depository'): Account
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
        'type' => $type,
        'subtype' => $type === 'credit' ? 'credit card' : 'checking',
    ]);
}

it('buckets a single account\'s balance into monthly periods', function (): void {
    $account = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'running_balance' => 990]);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-02-10', 'running_balance' => 980]);
    Transaction::factory()->for($account)->create(['name' => 'C', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-03-10', 'running_balance' => 970]);

    $result = BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-03-31'),
        'monthly',
    );

    expect($result['periods'])->toBe(['Jan 2026', 'Feb 2026', 'Mar 2026']);
    expect($result['assets'])->toBe([990.0, 980.0, 970.0]);
    expect($result['liabilities'])->toBe([0.0, 0.0, 0.0]);
    expect($result['net'])->toBe([990.0, 980.0, 970.0]);
});

it('carries the last known balance forward into periods with no activity', function (): void {
    $account = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'running_balance' => 500]);
    // No transactions in February or March — balance should stay 500.

    $result = BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-03-31'),
        'monthly',
    );

    expect($result['assets'])->toBe([500.0, 500.0, 500.0]);
});

it('does not fabricate a balance for periods before an account\'s first transaction', function (): void {
    $earlyAccount = makeAccountForBalanceTrendTest();
    $lateAccount = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($earlyAccount)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'running_balance' => 100]);
    Transaction::factory()->for($lateAccount)->create(['name' => 'B', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-03-10', 'running_balance' => 200]);

    $result = BuildBalanceTrendAction::run(
        collect([$earlyAccount, $lateAccount]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-03-31'),
        'monthly',
    );

    // January/February only ever see $earlyAccount (lateAccount doesn't exist yet);
    // March is the first period where both contribute.
    expect($result['assets'])->toBe([100.0, 100.0, 300.0]);
});

it('classifies credit accounts as liabilities and subtracts them from net', function (): void {
    $checking = makeAccountForBalanceTrendTest('depository');
    $card = makeAccountForBalanceTrendTest('credit');

    Transaction::factory()->for($checking)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-10', 'running_balance' => 1000]);
    Transaction::factory()->for($card)->create(['name' => 'B', 'amount' => 50, 'currency' => 'USD', 'created_at' => '2026-01-15', 'running_balance' => 300]);

    $result = BuildBalanceTrendAction::run(
        collect([$checking, $card]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'monthly',
    );

    expect($result['assets'])->toBe([1000.0]);
    expect($result['liabilities'])->toBe([300.0]);
    expect($result['net'])->toBe([700.0]);
});

it('groups into quarterly periods with the expected labels', function (): void {
    $account = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-02-10', 'running_balance' => 100]);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-05-10', 'running_balance' => 200]);

    $result = BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-06-30'),
        'quarterly',
    );

    expect($result['periods'])->toBe(['Q1 2026', 'Q2 2026']);
    expect($result['assets'])->toBe([100.0, 200.0]);
});

it('groups into yearly periods with the expected labels', function (): void {
    $account = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2025-06-10', 'running_balance' => 100]);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-06-10', 'running_balance' => 150]);

    $result = BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2025-01-01'),
        Carbon::parse('2026-12-31'),
        'yearly',
    );

    expect($result['periods'])->toBe(['2025', '2026']);
    expect($result['assets'])->toBe([100.0, 150.0]);
});

it('groups into daily periods with the expected labels', function (): void {
    $account = makeAccountForBalanceTrendTest();

    Transaction::factory()->for($account)->create(['name' => 'A', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-05', 'running_balance' => 100]);
    Transaction::factory()->for($account)->create(['name' => 'B', 'amount' => -10, 'currency' => 'USD', 'created_at' => '2026-01-06', 'running_balance' => 90]);

    $result = BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-05'),
        Carbon::parse('2026-01-06'),
        'daily',
    );

    expect($result['periods'])->toBe(['Jan 5, 2026', 'Jan 6, 2026']);
    expect($result['assets'])->toBe([100.0, 90.0]);
});

it('rejects an invalid granularity', function (): void {
    $account = makeAccountForBalanceTrendTest();

    expect(fn (): array => BuildBalanceTrendAction::run(
        collect([$account]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        'weekly',
    ))->toThrow(InvalidArgumentException::class);
});
