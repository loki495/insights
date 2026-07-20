<?php

declare(strict_types=1);

use App\Console\Commands\PullTransactions;
use App\Models\LinkedAccount;
use App\Models\User;
use Livewire\Livewire;

function makeLinkedAccountForAutoPullTest(User $user, array $overrides = []): LinkedAccount
{
    return LinkedAccount::factory()->for($user)->create(array_merge([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ], $overrides));
}

it('isAutoPullDue is false when auto-pull is disabled', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => false]);

    expect($linkedAccount->isAutoPullDue())->toBeFalse();
});

it('isAutoPullDue is false for a closed institution even if enabled', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => true, 'closed_at' => now()]);

    expect($linkedAccount->isAutoPullDue())->toBeFalse();
});

it('isAutoPullDue is true when enabled and never pulled before', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => true, 'last_pulled_at' => null]);

    expect($linkedAccount->isAutoPullDue())->toBeTrue();
});

it('isAutoPullDue is false when pulled more recently than the interval', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, [
        'auto_pull_enabled' => true,
        'auto_pull_interval_value' => 24,
        'auto_pull_interval_unit' => 'hours',
        'last_pulled_at' => now()->subHours(2),
    ]);

    expect($linkedAccount->isAutoPullDue())->toBeFalse();
});

it('isAutoPullDue is true once the interval has elapsed since the last pull, in either unit', function (): void {
    $user = User::factory()->create();

    $dueHours = makeLinkedAccountForAutoPullTest($user, [
        'auto_pull_enabled' => true, 'auto_pull_interval_value' => 6, 'auto_pull_interval_unit' => 'hours',
        'last_pulled_at' => now()->subHours(7),
    ]);
    $dueDays = makeLinkedAccountForAutoPullTest($user, [
        'auto_pull_enabled' => true, 'auto_pull_interval_value' => 2, 'auto_pull_interval_unit' => 'days',
        'last_pulled_at' => now()->subDays(3),
    ]);
    $notYetDueDays = makeLinkedAccountForAutoPullTest($user, [
        'auto_pull_enabled' => true, 'auto_pull_interval_value' => 10, 'auto_pull_interval_unit' => 'days',
        'last_pulled_at' => now()->subDays(3),
    ]);

    expect($dueHours->isAutoPullDue())->toBeTrue();
    expect($dueDays->isAutoPullDue())->toBeTrue();
    expect($notYetDueDays->isAutoPullDue())->toBeFalse();
});

it('updateAutoPull saves the enabled flag and interval via the Livewire component', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => false]);

    Livewire::test('admin.linked-accounts.index')
        ->call('updateAutoPull', $linkedAccount->id, true, 3, 'days');

    $linkedAccount->refresh();
    expect($linkedAccount->auto_pull_enabled)->toBeTrue();
    expect($linkedAccount->auto_pull_interval_value)->toBe(3);
    expect($linkedAccount->auto_pull_interval_unit)->toBe('days');
});

it('updateAutoPull rejects an invalid interval unit', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $linkedAccount = makeLinkedAccountForAutoPullTest($user);

    Livewire::test('admin.linked-accounts.index')
        ->call('updateAutoPull', $linkedAccount->id, true, 1, 'fortnights');
})->throws(InvalidArgumentException::class);

it('updateAutoPull refuses to update another user\'s linked account', function (): void {
    $owner = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($owner);

    $otherUser = User::factory()->create();
    test()->actingAs($otherUser);

    Livewire::test('admin.linked-accounts.index')
        ->call('updateAutoPull', $linkedAccount->id, true, 1, 'days')
        ->assertForbidden();
});

it('the scheduled transactions:pull run does not attempt anything when nothing is due', function (): void {
    $user = User::factory()->create();
    makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => false]);

    // No exception / no attempted Plaid call — proven by this completing at all, since a real
    // pull attempt (blank test Plaid credentials) would fail loudly rather than silently.
    $this->artisan(PullTransactions::class)->assertSuccessful();
});

it('an explicit linked_account_id bypasses the auto_pull_enabled filter, but still bails out for a closed institution', function (): void {
    $user = User::factory()->create();
    $linkedAccount = makeLinkedAccountForAutoPullTest($user, ['auto_pull_enabled' => false, 'closed_at' => now()]);

    $this->artisan(PullTransactions::class, ['linked_account_id' => $linkedAccount->id])->assertSuccessful();
});
