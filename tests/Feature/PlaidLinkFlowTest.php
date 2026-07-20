<?php

declare(strict_types=1);

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

    expect(LinkedAccount::where('user_id', $user->id)->count())->toBe(1);
    expect(LinkedAccount::where('user_id', $user->id)->first()->item_id)->toBe('new-item');
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
    });

    Livewire::test('admin.linked-accounts.index')
        ->call('linkAccount', $linkedAccount->id)
        ->call('exchangePublicToken', 'fake-public-token');

    expect(LinkedAccount::where('user_id', $user->id)->count())->toBe(1);
    $linkedAccount->refresh();
    expect($linkedAccount->item_id)->toBe('refreshed-item');
    expect($linkedAccount->access_token)->toBe('refreshed-token');
});

it('does not let a tampered client payload overwrite updating_linked_account_id', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    Livewire::test('admin.linked-accounts.index')
        ->set('updating_linked_account_id', 999);
})->throws(CannotUpdateLockedPropertyException::class);
