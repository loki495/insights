<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForBalanceReportTest(User $user, string $type = 'depository', array $overrides = []): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Account',
        'official_name' => 'Account Official',
        'type' => $type,
        'subtype' => $type === 'credit' ? 'credit card' : 'checking',
        'current_balance' => 0,
    ], $overrides));
}

test('guests are redirected to the login page', function (): void {
    $response = $this->get('/reports/balance');
    $response->assertRedirect('/login');
});

test('shows assets, liabilities, and net totals for tracked accounts', function (): void {
    $user = User::factory()->create();
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Checking', 'current_balance' => 1000]);
    makeAccountForBalanceReportTest($user, 'credit', ['name' => 'Card', 'current_balance' => 300]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertViewHas('assetsTotal', 1000.0);
    $test->assertViewHas('liabilitiesTotal', 300.0);
    $test->assertViewHas('netTotal', 700.0);
});

test('excludes reference and excluded accounts from the totals', function (): void {
    $user = User::factory()->create();
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Checking', 'current_balance' => 1000]);
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Reference Only', 'current_balance' => 5000, 'tracking_mode' => 'reference']);
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Excluded', 'current_balance' => 8000, 'tracking_mode' => 'excluded']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertViewHas('assetsTotal', 1000.0);
});

test('excludes accounts belonging to a closed linked institution', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'closed_at' => now(),
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Closed Bank Account',
        'official_name' => 'Closed', 'type' => 'depository', 'subtype' => 'checking', 'current_balance' => 9999,
    ]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertViewHas('assetsTotal', 0.0);
});

test('shows a placeholder when there is nothing to chart', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertSee('Nothing to chart for the current date range.');
});

test('renders a trend chart once tracked accounts have transaction history', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Checking', 'current_balance' => 1000]);
    Transaction::factory()->for($account)->create(['name' => 'Deposit', 'amount' => 1000, 'currency' => 'USD', 'running_balance' => 1000]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertDontSee('Nothing to chart for the current date range.');
});

test('account_ids empty shows totals across every tracked account', function (): void {
    $user = User::factory()->create();
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Checking', 'current_balance' => 1000]);
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Savings', 'current_balance' => 500]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index');

    $test->assertViewHas('assetsTotal', 1500.0);
});

test('account_ids filters the totals down to just the selected accounts', function (): void {
    $user = User::factory()->create();
    $checking = makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Checking', 'current_balance' => 1000]);
    makeAccountForBalanceReportTest($user, 'depository', ['name' => 'Savings', 'current_balance' => 500]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.balance.index')
        ->set('account_ids', [$checking->id]);

    $test->assertViewHas('assetsTotal', 1000.0);
    $test->assertViewHas('assetAccounts', fn ($accounts): bool => $accounts->pluck('id')->all() === [$checking->id]);
});

test('account_ids is intersected against the user\'s own tracked accounts (IDOR check)', function (): void {
    $owner = User::factory()->create();
    $ownAccount = makeAccountForBalanceReportTest($owner, 'depository', ['name' => 'Mine', 'current_balance' => 1000]);

    $otherUser = User::factory()->create();
    $otherAccount = makeAccountForBalanceReportTest($otherUser, 'depository', ['name' => 'Not Mine', 'current_balance' => 5000]);

    $this->actingAs($owner);

    $test = Livewire::test('admin.reports.balance.index')
        ->set('account_ids', [$ownAccount->id, $otherAccount->id]);

    $test->assertViewHas('assetsTotal', 1000.0);
});
