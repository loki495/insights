<?php

declare(strict_types=1);

use App\Actions\UpdateAccountAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;

function plaidAccountInfo(array $overrides = []): array
{
    return array_merge([
        'account_id' => 'plaid_account_1',
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
        'balances' => [
            'iso_currency_code' => 'USD',
            'available' => 100.0,
            'current' => 150.0,
            'limit' => null,
        ],
    ], $overrides);
}

it('does not swap available/current balance when creating a brand new account', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    UpdateAccountAction::run(plaidAccountInfo(), $linkedAccount);

    $account = Account::where('plaid_account_id', 'plaid_account_1')->firstOrFail();
    expect($account->available_balance)->toEqual(100);
    expect($account->current_balance)->toEqual(150);
});

it('keeps the same available/current mapping when updating an existing account', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'old_plaid_id', 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
        'available_balance' => 0, 'current_balance' => 0,
    ]);

    UpdateAccountAction::run(plaidAccountInfo(['account_id' => 'new_plaid_id']), $linkedAccount);

    $account = Account::where('mask', '0000')->firstOrFail();
    expect($account->plaid_account_id)->toBe('new_plaid_id');
    expect($account->available_balance)->toEqual(100);
    expect($account->current_balance)->toEqual(150);
});

it('does not match an identical-looking account belonging to a different linked account/item', function (): void {
    $user = User::factory()->create();
    $otherLinkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_other', 'access_token' => 'access_other',
    ]);
    $otherAccount = Account::factory()->for($otherLinkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'other_plaid_id', 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
        'available_balance' => 0, 'current_balance' => 0,
    ]);

    $thisLinkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_this', 'access_token' => 'access_this',
    ]);

    UpdateAccountAction::run(plaidAccountInfo(['account_id' => 'this_plaid_id']), $thisLinkedAccount);

    // A new account was created for this linked account, not merged into the other item's.
    expect(Account::where('plaid_account_id', 'this_plaid_id')->exists())->toBeTrue();
    $otherAccount->refresh();
    expect($otherAccount->plaid_account_id)->toBe('other_plaid_id');
    expect($otherAccount->available_balance)->toEqual(0);
    expect($otherAccount->current_balance)->toEqual(0);
    expect(Account::count())->toBe(2);
});
