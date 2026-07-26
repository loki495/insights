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

it('cleans stray U+FFFD replacement characters out of the account name/official_name on create', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    UpdateAccountAction::run(plaidAccountInfo([
        'name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
        'official_name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
    ]), $linkedAccount);

    $account = Account::where('plaid_account_id', 'plaid_account_1')->firstOrFail();
    expect($account->name)->toBe('WELLS FARGO REFLECT VISA CARD');
    expect($account->official_name)->toBe('WELLS FARGO REFLECT VISA CARD');
});

it('matches an existing account by plaid_account_id even when the incoming name no longer matches the cleaned stored name', function (): void {
    // Real bug: after cleanPlaidText() normalizes a stored name, Plaid keeps sending the raw
    // (still-corrupted) name on every subsequent sync. A name-based match would never find the
    // existing row again and would create a duplicate Account sharing the same plaid_account_id.
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_account_1', 'mask' => '0000',
        'name' => 'WELLS FARGO REFLECT VISA CARD',
        'official_name' => 'WELLS FARGO REFLECT VISA CARD',
        'type' => 'depository', 'subtype' => 'checking',
        'available_balance' => 0, 'current_balance' => 0,
    ]);

    UpdateAccountAction::run(plaidAccountInfo([
        'account_id' => 'plaid_account_1',
        'name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
        'official_name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
    ]), $linkedAccount);

    expect(Account::count())->toBe(1);
    $account = Account::where('plaid_account_id', 'plaid_account_1')->firstOrFail();
    expect($account->name)->toBe('WELLS FARGO REFLECT VISA CARD');
    expect($account->available_balance)->toEqual(100);
    expect($account->current_balance)->toEqual(150);
});

it('cleans stray U+FFFD replacement characters out of the account name/official_name on update', function (): void {
    // The match query looks the existing row up by exact name/official_name equality against
    // the incoming Plaid payload, so the pre-existing row here has to already carry the
    // (pre-fix) corrupted name — exactly what a real previously-synced, not-yet-backfilled
    // account would look like — for the match to succeed and exercise the update path at all.
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_account_1', 'mask' => '0000',
        'name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
        'official_name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
        'type' => 'depository', 'subtype' => 'checking',
    ]);

    UpdateAccountAction::run(plaidAccountInfo([
        'name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
        'official_name' => "WELLS FARGO REFLECT VISA\u{FFFD}\u{FFFD} CARD",
    ]), $linkedAccount);

    $account = Account::where('plaid_account_id', 'plaid_account_1')->firstOrFail();
    expect($account->name)->toBe('WELLS FARGO REFLECT VISA CARD');
    expect($account->official_name)->toBe('WELLS FARGO REFLECT VISA CARD');
});
