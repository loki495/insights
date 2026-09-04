<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Plaid\PlaidService;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

function makeLinkedAccountForPullDataTest(User $user, bool $isDemo): LinkedAccount
{
    return LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'is_demo' => $isDemo,
    ]);
}

function fakePullDataFailure(): void
{
    $mock = Mockery::mock(PlaidService::class);
    $mock->shouldReceive('getItemAccounts')->andThrow(new RuntimeException('Visible sync failure'));
    app()->bind(PlaidService::class, fn () => $mock);
}

it('hides the Pull Data button on the accounts index for a demo linked account', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = makeLinkedAccountForPullDataTest($user, true);

    Livewire::test('admin.accounts.index', ['linkedAccount' => $linkedAccount])
        ->assertDontSee('Pull Data');
});

it('shows the Pull Data button on the accounts index for a real linked account', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = makeLinkedAccountForPullDataTest($user, false);

    Livewire::test('admin.accounts.index', ['linkedAccount' => $linkedAccount])
        ->assertSee('Pull Data');
});

it('hides the Pull Data button on the single-account view for a demo linked account', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = makeLinkedAccountForPullDataTest($user, true);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);

    Livewire::test('admin.accounts.show', ['account' => $account])
        ->assertDontSee('Pull Data');
});

it('PullLinkedAccountTransactionsAction is a no-op for a demo linked account, without calling Plaid', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForPullDataTest($user, true);

    // A real access token would throw if it reached the Plaid client — not throwing proves the demo guard fired.
    expect(fn () => PullLinkedAccountTransactionsAction::run($linkedAccount))->not->toThrow(Throwable::class);
});

it('surfaces a manual pull failure from the accounts index when debug mode is enabled', function (): void {
    config(['app.debug' => true]);
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForPullDataTest($user, false);
    test()->actingAs($user);
    fakePullDataFailure();

    expect(fn () => Livewire::test('admin.accounts.index', ['linkedAccount' => $linkedAccount])
        ->call('pullData'))
        ->toThrow(RuntimeException::class, 'Visible sync failure');
});

it('surfaces a manual pull failure from a single account when debug mode is enabled', function (): void {
    config(['app.debug' => true]);
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForPullDataTest($user, false);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    test()->actingAs($user);
    fakePullDataFailure();

    expect(fn () => Livewire::test('admin.accounts.show', ['account' => $account])
        ->call('pullData'))
        ->toThrow(RuntimeException::class, 'Visible sync failure');
});

it('keeps manual pull failures graceful when debug mode is disabled', function (): void {
    config(['app.debug' => false]);
    Exceptions::fake();
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForPullDataTest($user, false);
    test()->actingAs($user);
    fakePullDataFailure();

    Livewire::test('admin.accounts.index', ['linkedAccount' => $linkedAccount])
        ->call('pullData')
        ->assertRedirect(route('linked-accounts.accounts.index', $linkedAccount));

    expect($linkedAccount->fresh()->last_sync_error)->toBe('Visible sync failure');

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => $exception->getMessage() === 'Visible sync failure',
    );
});
