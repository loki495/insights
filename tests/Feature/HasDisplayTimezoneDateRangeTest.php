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

it('defaults the "From" field to Jan 1 of the current year in the display timezone, not a UTC-shifted date', function (): void {
    // "Start of year" must be computed directly in the display timezone — doing it in UTC and
    // converting for display afterward can land on Dec 31 the previous year in any timezone west
    // of UTC.
    config(['app.display_timezone' => 'America/Los_Angeles']);

    $account = makeAccountForDisplayTimezoneTest();

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $expectedYear = now('America/Los_Angeles')->year;
    expect($test->get('date_from_local'))->toBe("{$expectedYear}-01-01T00:00");

    // date_from (the query-timezone value) must represent that exact same instant.
    expect(Carbon::parse($test->get('date_from'))->setTimezone('America/Los_Angeles')->format('Y-m-d\TH:i'))
        ->toBe("{$expectedYear}-01-01T00:00");
});

it('defaults the "From" field correctly on the Balance report too', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    User::factory()->create();
    test()->actingAs(User::first());

    $test = Livewire::test('admin.reports.balance.index');

    $expectedYear = now('America/Los_Angeles')->year;
    expect($test->get('date_from_local'))->toBe("{$expectedYear}-01-01T00:00");
});

it('defaults the "From" field correctly on the Income/Expense report too', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    User::factory()->create();
    test()->actingAs(User::first());

    $test = Livewire::test('admin.reports.income-expense.index');

    $expectedYear = now('America/Los_Angeles')->year;
    expect($test->get('date_from_local'))->toBe("{$expectedYear}-01-01T00:00");
});

it('does not hardcode a specific year, but always tracks the current one', function (): void {
    config(['app.display_timezone' => 'America/Los_Angeles']);

    // Travel a couple of years into the future and confirm the default follows, rather than
    // being pinned to whatever year this test suite happened to be written in.
    Carbon::setTestNow(Carbon::parse('2031-05-15 12:00:00', 'UTC'));

    $account = makeAccountForDisplayTimezoneTest();
    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect($test->get('date_from_local'))->toBe('2031-01-01T00:00');

    Carbon::setTestNow();
});
