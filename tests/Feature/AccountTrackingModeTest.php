<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForTrackingTest(User $user, array $overrides = []): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Account',
        'official_name' => 'Account Official', 'type' => 'depository', 'subtype' => 'checking',
    ], $overrides));
}

it('excludes reference and excluded accounts from the aggregate transaction view', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $tracked = makeAccountForTrackingTest($user, ['name' => 'Tracked']);
    $reference = makeAccountForTrackingTest($user, ['name' => 'Reference', 'tracking_mode' => 'reference']);
    $excluded = makeAccountForTrackingTest($user, ['name' => 'Excluded', 'tracking_mode' => 'excluded']);

    Transaction::factory()->for($tracked)->create(['name' => 'Tracked Txn', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($reference)->create(['name' => 'Reference Txn', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($excluded)->create(['name' => 'Excluded Txn', 'amount' => -10, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions');

    expect($test->instance()->getTransactionsQuery()->pluck('name')->all())->toBe(['Tracked Txn']);
});

it('still shows a non-tracked account\'s own transactions when viewed directly', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $excluded = makeAccountForTrackingTest($user, ['name' => 'Excluded', 'tracking_mode' => 'excluded']);
    Transaction::factory()->for($excluded)->create(['name' => 'Excluded Txn', 'amount' => -10, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $excluded]);

    expect($test->instance()->getTransactionsQuery()->pluck('name')->all())->toBe(['Excluded Txn']);
});

it('the account filter list only shows the current user\'s tracked accounts', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    test()->actingAs($user);

    $tracked = makeAccountForTrackingTest($user, ['name' => 'Mine Tracked']);
    makeAccountForTrackingTest($user, ['name' => 'Mine Excluded', 'tracking_mode' => 'excluded']);
    makeAccountForTrackingTest($otherUser, ['name' => 'Someone Else\'s']);

    $test = Livewire::test('components.transactions');

    expect($test->instance()->accounts->pluck('name')->all())->toBe(['Mine Tracked']);
});

it('updateTrackingMode changes an owned account\'s tracking mode', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = LinkedAccount::factory()->for($user)->create(['item_id' => 'item_1', 'access_token' => 'token_1']);
    $account = makeAccountForTrackingTest($user);

    Livewire::test('admin.accounts.index', ['linkedAccount' => $account->linked_account])
        ->call('updateTrackingMode', $account->id, 'reference');

    expect($account->fresh()->tracking_mode)->toBe('reference');
});

it('updateTrackingMode rejects an invalid tracking mode', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $account = makeAccountForTrackingTest($user);

    $test = Livewire::test('admin.accounts.index', ['linkedAccount' => $account->linked_account]);

    expect(fn () => $test->call('updateTrackingMode', $account->id, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

it('updateTrackingMode refuses to update another user\'s account, even from a legitimately-mounted component', function (): void {
    // Mount as the attacker's OWN linked account (passes mount()'s own authorize check), then
    // try to tamper with a different user's account id via the action's argument directly —
    // this exercises updateTrackingMode()'s own authorize() call, not just mount()'s.
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $victimAccount = makeAccountForTrackingTest($owner);
    $attackerAccount = makeAccountForTrackingTest($attacker);

    test()->actingAs($attacker);

    Livewire::test('admin.accounts.index', ['linkedAccount' => $attackerAccount->linked_account])
        ->call('updateTrackingMode', $victimAccount->id, 'excluded')
        ->assertForbidden();

    expect($victimAccount->fresh()->tracking_mode)->toBe('tracked');
});
