<?php

declare(strict_types=1);

use App\Enums\AccountDisabledReason;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Plaid\PlaidService;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * plaid()/PlaidService is always resolved via app(PlaidService::class, ['environment' => ...]) —
 * a non-empty $parameters array, which makes Laravel's container skip any instance()-bound mock
 * (container only returns a bound instance when $parameters is empty) and build a real one
 * instead. A plain bind() (not instance()) doesn't have that problem, since it goes through the
 * normal concrete-resolution path regardless of $parameters — confirmed empirically before
 * writing these tests, since this app has no existing Plaid-mocking test convention to follow.
 */
function fakePlaid(callable $expectations): void
{
    $mock = Mockery::mock(PlaidService::class);
    $expectations($mock);
    app()->bind(PlaidService::class, fn () => $mock);
}

it('linking a brand new institution does not crash (regression: null LinkedAccount)', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    fakePlaid(function ($mock): void {
        $mock->shouldReceive('getLinkToken')->once()->andReturn(['link_token' => 'fake-link-token']);
    });

    Livewire::test('admin.linked-accounts.index')
        ->call('linkAccount')
        ->assertDispatched('triggerPlaid', link_token: 'fake-link-token');
});

it('completing a brand new Link flow creates a new LinkedAccount', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    fakePlaid(function ($mock): void {
        $mock->shouldReceive('getLinkToken')->once()->andReturn(['link_token' => 'fake-link-token']);
        $mock->shouldReceive('exchangePublicToken')->once()->andReturn(['item_id' => 'new-item', 'access_token' => 'new-token']);
        $mock->shouldReceive('getItemInfo')->once()->andReturn(['item' => ['institution_name' => 'New Bank']]);
    });

    Livewire::test('admin.linked-accounts.index')
        ->call('linkAccount')
        ->call('exchangePublicToken', 'fake-public-token');

    expect(LinkedAccount::where('user_id', $user->id)->count())->toBe(1)
        ->and(LinkedAccount::where('user_id', $user->id)->first()->item_id)->toBe('new-item');
});

it('completing an "Update Access Token" flow updates the existing LinkedAccount instead of creating a duplicate', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'old-item', 'access_token' => 'old-token', 'provider_name' => 'Old Bank',
    ]);
    test()->actingAs($user);

    fakePlaid(function ($mock): void {
        $mock->shouldReceive('getLinkToken')->once()->andReturn(['link_token' => 'fake-link-token']);
        $mock->shouldReceive('exchangePublicToken')->once()->andReturn(['item_id' => 'refreshed-item', 'access_token' => 'refreshed-token']);
        $mock->shouldReceive('getItemInfo')->once()->andReturn(['item' => ['institution_name' => 'Old Bank']]);
        $mock->shouldReceive('getItemAccounts')->once()->andReturn(['accounts' => [[
            'account_id' => 'selected_account', 'mask' => '0000', 'name' => 'Checking',
            'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
            'balances' => ['iso_currency_code' => 'USD', 'available' => 100.0, 'current' => 100.0, 'limit' => null],
        ]]]);
    });

    Livewire::test('admin.linked-accounts.index')
        ->call('linkAccount', $linkedAccount->id)
        ->call('exchangePublicToken', 'fake-public-token');

    expect(LinkedAccount::where('user_id', $user->id)->count())->toBe(1);
    $linkedAccount->refresh();
    expect($linkedAccount->item_id)->toBe('refreshed-item')
        ->and($linkedAccount->access_token)->toBe('refreshed-token');
});

it('a deliberate Link update restores a manually disabled account selected at Plaid', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'old-item', 'access_token' => 'old-token', 'provider_name' => 'Old Bank',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'selected_account', 'disabled_at' => now(),
        'disabled_reason' => AccountDisabledReason::Manual, 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    test()->actingAs($user);

    fakePlaid(function ($mock): void {
        $mock->shouldReceive('getLinkToken')->once()->andReturn(['link_token' => 'fake-link-token']);
        $mock->shouldReceive('exchangePublicToken')->once()->andReturn(['item_id' => 'refreshed-item', 'access_token' => 'refreshed-token']);
        $mock->shouldReceive('getItemInfo')->once()->andReturn(['item' => ['institution_name' => 'Old Bank']]);
        $mock->shouldReceive('getItemAccounts')->once()->andReturn(['accounts' => [[
            'account_id' => 'selected_account', 'mask' => '0000', 'name' => 'Checking',
            'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
            'balances' => ['iso_currency_code' => 'USD', 'available' => 100.0, 'current' => 100.0, 'limit' => null],
        ]]]);
    });

    Livewire::test('admin.linked-accounts.index')
        ->call('linkAccount', $linkedAccount->id)
        ->call('exchangePublicToken', 'fake-public-token');

    expect($account->fresh()->disabled_at)->toBeNull()
        ->and($account->fresh()->disabled_reason)->toBeNull();
});

it('does not let a tampered client payload overwrite updating_linked_account_id', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    Livewire::test('admin.linked-accounts.index')
        ->set('updating_linked_account_id', 999);
})->throws(CannotUpdateLockedPropertyException::class);
