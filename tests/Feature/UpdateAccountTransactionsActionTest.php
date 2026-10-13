<?php

declare(strict_types=1);

use App\Actions\UpdateAccountTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function plaidTransactionInfo(array $overrides = []): array
{
    return array_merge([
        'transaction_id' => 'txn_1',
        'account_id' => 'plaid_account_1',
        'amount' => 25.0,
        'authorized_date' => '2026-06-10',
        'date' => '2026-06-10',
        'iso_currency_code' => 'USD',
        'logo_url' => null,
        'merchant_name' => 'Coffee Shop',
        'merchant_entity_id' => 'merchant_1',
        'name' => 'Coffee Shop Purchase',
        'payment_channel' => 'in store',
        'website' => null,
        'category' => [],
        'category_id' => null,
    ], $overrides);
}

function makeAccountForTransactionsTest(array $overrides = []): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_account_1',
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ], $overrides));
}

it('creates a new transaction from an "added" entry, flipping the sign so a debit is negative', function (): void {
    $account = makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['amount' => 25.0]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->amount)->toEqual(-25.0)
        ->and($transaction->transaction_type)->toBe('Debit')
        ->and($transaction->account_id)->toBe($account->id);
});

it('flips a negative Plaid amount (a refund/credit) to a positive Credit transaction', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['amount' => -25.0]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->amount)->toEqual(25.0)
        ->and($transaction->transaction_type)->toBe('Credit');
});

it('updates the existing transaction in place on a "modified" entry instead of duplicating it', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['name' => 'Coffee Shop Purchase', 'amount' => 25.0]), 'added', onlyLinkedAccount());
    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['name' => 'Coffee Shop Purchase (corrected)', 'amount' => 30.0]), 'modified', onlyLinkedAccount());

    expect(Transaction::where('transaction_id', 'txn_1')->count())->toBe(1);
    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->name)->toBe('Coffee Shop Purchase (corrected)')
        ->and($transaction->amount)->toEqual(-30.0);
});

it('deletes the transaction on a "removed" entry', function (): void {
    makeAccountForTransactionsTest();
    UpdateAccountTransactionsAction::run(plaidTransactionInfo(), 'added', onlyLinkedAccount());
    expect(Transaction::where('transaction_id', 'txn_1')->count())->toBe(1);

    UpdateAccountTransactionsAction::run(['transaction_id' => 'txn_1'], 'removed', onlyLinkedAccount());

    expect(Transaction::where('transaction_id', 'txn_1')->count())->toBe(0);
});

it('throws when the transaction references an account that has not been synced yet', function (): void {
    $linkedAccount = LinkedAccount::factory()->for(User::factory()->create())->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['account_id' => 'no_such_plaid_account']), 'added', $linkedAccount);
})->throws(Exception::class, 'Account not found - no_such_plaid_account');

it('cleans stray U+FFFD replacement characters out of the transaction name/merchant_name', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo([
        'name' => "SOME STORE\u{FFFD}\u{FFFD} NAME",
        'merchant_name' => "SOME STORE\u{FFFD}\u{FFFD} NAME",
    ]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->name)->toBe('SOME STORE NAME')
        ->and($transaction->merchant_name)->toBe('SOME STORE NAME');
});

it('leaves merchant_name null when Plaid sends no merchant name', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['merchant_name' => null]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->merchant_name)->toBeNull();
});

it('falls back to the date-only fields when datetime fields are absent', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo([
        'authorized_date' => '2026-06-10',
        'date' => '2026-06-11',
    ]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->authorized_at->toDateString())->toBe('2026-06-10')
        ->and($transaction->created_at->toDateString())->toBe('2026-06-11');
});

it('prefers the datetime fields over the date-only fields when both are present', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo([
        'authorized_datetime' => '2026-06-10T08:30:00',
        'authorized_date' => '2026-06-01',
        'datetime' => '2026-06-10T08:30:00',
        'date' => '2026-06-01',
    ]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->authorized_at->toDateString())->toBe('2026-06-10');
});

it('resolves and persists the Plaid category onto the transaction', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo([
        'category' => ['Food and Drink', 'Restaurants'],
        'category_id' => '13005000',
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT'],
    ]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->original_category_id)->not->toBeNull()
        ->and($transaction->originalCategory->full_path)->toBe('Food and Drink > Restaurants');
});

it('leaves original_category_id null when Plaid sends no category data', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['category' => [], 'category_id' => null]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->original_category_id)->toBeNull();
});

it('classifies the transaction type from the resolved category via refreshType()', function (): void {
    makeAccountForTransactionsTest();

    UpdateAccountTransactionsAction::run(plaidTransactionInfo([
        'amount' => -500.0,
        'category' => ['Transfer', 'Deposit'],
        'category_id' => '21006000',
        'personal_finance_category' => ['primary' => 'TRANSFER_IN', 'detailed' => 'TRANSFER_IN_DEPOSIT'],
    ]), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->type)->toBe('transfer');
});

it('auto-categorizes a new transaction via a matching active rule', function (): void {
    $account = makeAccountForTransactionsTest();
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $rule = CategoryRule::factory()->for($account->linked_account->user)->for($coffee)->create();
    $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0])->conditions()->create(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'coffee']);

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['merchant_name' => 'Coffee Shop']), 'added', onlyLinkedAccount());

    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    expect($transaction->categories()->pluck('categories.id')->all())->toBe([$coffee->id]);
});

it('never lets a rule override an already-categorized transaction on a re-sync ("modified")', function (): void {
    $account = makeAccountForTransactionsTest();
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $rule = CategoryRule::factory()->for($account->linked_account->user)->for($coffee)->create();
    $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0])->conditions()->create(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'coffee']);

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['merchant_name' => 'Coffee Shop']), 'added', onlyLinkedAccount());
    $transaction = Transaction::where('transaction_id', 'txn_1')->firstOrFail();
    $transaction->categories()->sync([$groceries->id]);

    // Simulates Plaid re-sending the same transaction as "modified" (e.g. a pending->posted flip).
    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['merchant_name' => 'Coffee Shop']), 'modified', onlyLinkedAccount());

    expect($transaction->fresh()->categories()->pluck('categories.id')->all())->toBe([$groceries->id]);
});

it('does not touch another linked account\'s transaction that shares a plaid account id and transaction id', function (): void {
    $mine = makeAccountForTransactionsTest();
    $theirs = makeAccountForTransactionsTest();
    $theirTransaction = Transaction::factory()->for($theirs)->create([
        'transaction_id' => 'txn_1', 'name' => 'Theirs', 'amount' => -1, 'currency' => 'USD',
    ]);

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(['name' => 'Mine']), 'added', $mine->linked_account);

    expect($theirTransaction->fresh()->name)->toBe('Theirs')
        ->and(Transaction::where('transaction_id', 'txn_1')->count())->toBe(2)
        ->and(Transaction::where('account_id', $mine->id)->firstOrFail()->name)->toBe('Mine');
});

it('only removes the transaction belonging to the syncing linked account', function (): void {
    $mine = makeAccountForTransactionsTest();
    $theirs = makeAccountForTransactionsTest();
    Transaction::factory()->for($mine)->create(['transaction_id' => 'txn_1', 'name' => 'Mine', 'amount' => -1, 'currency' => 'USD']);
    Transaction::factory()->for($theirs)->create(['transaction_id' => 'txn_1', 'name' => 'Theirs', 'amount' => -1, 'currency' => 'USD']);

    UpdateAccountTransactionsAction::run(['transaction_id' => 'txn_1'], 'removed', $mine->linked_account);

    expect(Transaction::where('transaction_id', 'txn_1')->pluck('account_id')->all())->toBe([$theirs->id]);
});

it('throws rather than attaching to another linked account\'s plaid account id', function (): void {
    makeAccountForTransactionsTest();
    $other = LinkedAccount::factory()->for(User::factory()->create())->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    UpdateAccountTransactionsAction::run(plaidTransactionInfo(), 'added', $other);
})->throws(Exception::class, 'Account not found - plaid_account_1');
