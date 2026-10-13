<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForTransactionEditFormTest(): Account
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

    test()->actingAs($user);

    return $account;
}

it('saves a new transaction and redirects to the account it was created under', function (): void {
    $account = makeAccountForTransactionEditFormTest();

    Livewire::test('admin.transactions.edit', ['account' => $account])
        ->set('name', 'Coffee Shop')
        ->set('amount', -6)
        ->set('date', now()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertRedirect(route('linked-accounts.accounts.show', [
            'linkedAccount' => $account->linked_account,
            'account' => $account->id,
        ]));

    expect(Transaction::where('name', 'Coffee Shop')->where('account_id', $account->id)->exists())->toBeTrue();
});

it('updates an existing transaction in place rather than creating a new one', function (): void {
    $account = makeAccountForTransactionEditFormTest();
    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Original Name', 'amount' => -5, 'currency' => 'USD',
    ]);

    Livewire::test('admin.transactions.edit', ['transaction' => $transaction])
        ->set('name', 'Updated Name')
        ->set('amount', -99)
        ->call('save');

    expect(Transaction::count())->toBe(1);
    $transaction->refresh();
    expect($transaction->name)->toBe('Updated Name')
        ->and($transaction->amount)->toBe(-99);
});

it('shows a validation error instead of crashing when no account is selected', function (): void {
    makeAccountForTransactionEditFormTest();

    Livewire::test('admin.transactions.edit')
        ->set('name', 'No Account Transaction')
        ->set('amount', -10)
        ->set('date', now()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors(['account_id']);

    expect(Transaction::where('name', 'No Account Transaction')->exists())->toBeFalse();
});

it('shows a validation error instead of crashing when the name is left blank', function (): void {
    $account = makeAccountForTransactionEditFormTest();

    Livewire::test('admin.transactions.edit', ['account' => $account])
        ->set('name', '')
        ->set('amount', -10)
        ->set('date', now()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Transaction::where('account_id', $account->id)->exists())->toBeFalse();
});

it('only lists the signed-in user\'s own categories', function (): void {
    $account = makeAccountForTransactionEditFormTest();
    $mine = categoryFor($account->linked_account->user, 'Mine Only');
    $foreign = categoryFor(User::factory()->create(), 'Someone Elses');

    Livewire::test('admin.transactions.edit', ['account' => $account])
        ->assertSee('Mine Only')
        ->assertDontSee('Someone Elses')
        ->assertSeeHtml('<select wire:model="categories" multiple');

    expect($mine->id)->not->toBe($foreign->id);
});

it('rejects a category the user does not own and syncs nothing', function (): void {
    $account = makeAccountForTransactionEditFormTest();
    $foreign = categoryFor(User::factory()->create(), 'Someone Elses');
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Tx', 'amount' => -5, 'currency' => 'USD']);

    Livewire::test('admin.transactions.edit', ['transaction' => $transaction])
        ->set('categories', [$foreign->id])
        ->call('save')
        ->assertHasErrors(['categories.0' => 'in'])
        ->assertNoRedirect();

    expect($transaction->categories()->count())->toBe(0);
});

it('keeps both categories when editing a transaction that has two', function (): void {
    $account = makeAccountForTransactionEditFormTest();
    $user = $account->linked_account->user;
    $a = categoryFor($user, 'Cat A');
    $b = categoryFor($user, 'Cat B');
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Tx', 'amount' => -5, 'currency' => 'USD']);
    $transaction->categories()->sync([$a->id, $b->id]);

    Livewire::test('admin.transactions.edit', ['transaction' => $transaction])
        ->assertSet('categories', [$a->id, $b->id])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($transaction->categories()->pluck('categories.id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

it('keeps a Plaid row\'s original payload when edited', function (): void {
    $account = makeAccountForTransactionEditFormTest();
    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Plaid Row', 'amount' => -5, 'currency' => 'USD',
        'transaction_id' => 'txn_plaid_1', 'original' => ['transaction_id' => 'txn_plaid_1', 'name' => 'Raw'],
    ]);

    Livewire::test('admin.transactions.edit', ['transaction' => $transaction])
        ->set('name', 'Edited')
        ->call('save')
        ->assertHasNoErrors();

    expect($transaction->fresh()->original)->toBe(['transaction_id' => 'txn_plaid_1', 'name' => 'Raw']);
});

it('marks a manually created transaction as manual', function (): void {
    $account = makeAccountForTransactionEditFormTest();

    Livewire::test('admin.transactions.edit', ['account' => $account])
        ->set('name', 'Cash')
        ->set('amount', -3)
        ->set('date', now()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::where('name', 'Cash')->firstOrFail()->original)->toBe(['manual' => true]);
});
