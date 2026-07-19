<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

function makeAccountForDisplayTimezoneTest(): Account
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

it('shows the "To" field in the configured display timezone, not raw UTC', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    $account = makeAccountForDisplayTimezoneTest();

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $utcNow = Carbon::parse($test->get('date_to'));
    $expectedLocal = $utcNow->copy()->setTimezone('America/Los_Angeles')->format('Y-m-d\TH:i');

    expect($test->get('date_to_local'))->toBe($expectedLocal);
});

it('converts a manually-entered local date back to the query timezone', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    $account = makeAccountForDisplayTimezoneTest();

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('date_to_local', '2026-07-19T03:48');

    // 3:48am Los Angeles time on that date is 10:48am UTC (PDT is UTC-7 in July).
    expect(Carbon::parse($test->get('date_to'))->format('Y-m-d H:i'))->toBe('2026-07-19 10:48');
});

it('applies the same display-timezone conversion on the Balance report', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    User::factory()->create();
    test()->actingAs(User::first());

    $test = Livewire::test('admin.reports.balance.index');

    $utcNow = Carbon::parse($test->get('date_to'));
    $expectedLocal = $utcNow->copy()->setTimezone('America/Los_Angeles')->format('Y-m-d\TH:i');

    expect($test->get('date_to_local'))->toBe($expectedLocal);
});

it('applies the same display-timezone conversion on the Income/Expense report', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    User::factory()->create();
    test()->actingAs(User::first());

    $test = Livewire::test('admin.reports.income-expense.index');

    $utcNow = Carbon::parse($test->get('date_to'));
    $expectedLocal = $utcNow->copy()->setTimezone('America/Los_Angeles')->format('Y-m-d\TH:i');

    expect($test->get('date_to_local'))->toBe($expectedLocal);
});
