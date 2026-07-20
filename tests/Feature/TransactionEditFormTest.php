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
