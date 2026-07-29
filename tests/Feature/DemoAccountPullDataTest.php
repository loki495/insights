<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;
use Livewire\Livewire;

function makeLinkedAccountForPullDataTest(User $user, bool $isDemo): LinkedAccount
{
    return LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'is_demo' => $isDemo,
    ]);
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
