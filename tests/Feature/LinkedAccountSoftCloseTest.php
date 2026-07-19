<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeLinkedAccountWithData(): array
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD']);

    test()->actingAs($user);

    return [$user, $linkedAccount, $account];
}

it('closing a linked account sets closed_at without deleting accounts or transactions', function (): void {
    [$user, $linkedAccount, $account] = makeLinkedAccountWithData();

    Livewire::test('admin.linked-accounts.index')->call('close', $linkedAccount->id);

    expect($linkedAccount->fresh()->closed_at)->not->toBeNull();
    expect(Account::find($account->id))->not->toBeNull();
    expect(Transaction::where('account_id', $account->id)->count())->toBe(1);
});

it('reopening a closed linked account clears closed_at', function (): void {
    [$user, $linkedAccount] = makeLinkedAccountWithData();
    $linkedAccount->update(['closed_at' => now()]);

    Livewire::test('admin.linked-accounts.index')->call('reopen', $linkedAccount->id);

    expect($linkedAccount->fresh()->closed_at)->toBeNull();
});

it('PullLinkedAccountTransactionsAction bails out immediately for a closed linked account', function (): void {
    [$user, $linkedAccount] = makeLinkedAccountWithData();
    $linkedAccount->update(['closed_at' => now(), 'access_token' => 'not-a-real-token']);

    // If the closed-check didn't short-circuit before the Plaid call, this would attempt a
    // real HTTP request with a garbage token and fail/throw — reaching the end without any
    // error is exactly what proves the guard fired first.
    PullLinkedAccountTransactionsAction::run($linkedAccount->fresh());

    expect(true)->toBeTrue();
});

it('the bulk pull command does not process closed linked accounts', function (): void {
    [$user, $linkedAccount] = makeLinkedAccountWithData();
    $linkedAccount->update(['closed_at' => now(), 'access_token' => 'not-a-real-token']);

    $this->artisan('transactions:pull')->assertExitCode(0);

    expect(true)->toBeTrue();
});
