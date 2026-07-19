<?php

declare(strict_types=1);

use App\Actions\UpdateAccountTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForTypeTest(array $overrides = []): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ], $overrides));
}

function plaidTransactionPayload(array $overrides = []): array
{
    return array_merge([
        'account_id' => 'plaid_account_placeholder',
        'transaction_id' => 'txn_'.uniqid(),
        'amount' => 10, // Plaid raw sign: positive = money OUT
        'name' => 'Test Transaction',
        'merchant_name' => null,
        'merchant_entity_id' => null,
        'payment_channel' => 'online',
        'iso_currency_code' => 'USD',
        'logo_url' => null,
        'website' => null,
        'date' => '2026-06-01',
        'datetime' => '2026-06-01T00:00:00Z',
        'authorized_date' => '2026-06-01',
        'authorized_datetime' => '2026-06-01T00:00:00Z',
        'category' => ['Transfer', 'Deposit'],
        'category_id' => '21005000',
        'personal_finance_category' => ['primary' => 'TRANSFER_IN', 'detailed' => 'TRANSFER_IN_ACCOUNT_TRANSFER', 'confidence_level' => 'HIGH'],
    ], $overrides);
}

// --- Transaction::classifyType() ---

it('classifies TRANSFER_IN/TRANSFER_OUT pf_primary as a transfer', function (): void {
    $category = OriginalCategory::make(['pf_primary' => 'TRANSFER_OUT', 'pf_detailed' => 'TRANSFER_OUT_ACCOUNT_TRANSFER']);

    expect(Transaction::classifyType($category, -100))->toBe('transfer');
});

it('classifies LOAN_PAYMENTS_CREDIT_CARD_PAYMENT as a transfer even though Plaid buckets it under LOAN_PAYMENTS', function (): void {
    $category = OriginalCategory::make(['pf_primary' => 'LOAN_PAYMENTS', 'pf_detailed' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT']);

    expect(Transaction::classifyType($category, -200))->toBe('transfer');
});

it('does not classify other LOAN_PAYMENTS as a transfer', function (): void {
    $category = OriginalCategory::make(['pf_primary' => 'LOAN_PAYMENTS', 'pf_detailed' => 'LOAN_PAYMENTS_PERSONAL_LOAN_PAYMENT']);

    expect(Transaction::classifyType($category, -200))->toBe('expense');
});

it('classifies pf_primary INCOME as income', function (): void {
    $category = OriginalCategory::make(['pf_primary' => 'INCOME', 'pf_detailed' => 'INCOME_WAGES']);

    expect(Transaction::classifyType($category, 500))->toBe('income');
});

it('falls back to amount sign when there is no useful pf_primary signal', function (): void {
    $category = OriginalCategory::make(['pf_primary' => 'FOOD_AND_DRINK', 'pf_detailed' => 'FOOD_AND_DRINK_RESTAURANT']);

    expect(Transaction::classifyType($category, -20))->toBe('expense');
    expect(Transaction::classifyType($category, 20))->toBe('income');
});

it('falls back to amount sign when there is no original category at all', function (): void {
    expect(Transaction::classifyType(null, -20))->toBe('expense');
    expect(Transaction::classifyType(null, 20))->toBe('income');
});

// --- Transaction::refreshType() ---

it('refreshType layers the "Transfers" category tag on top of the Plaid-derived guess', function (): void {
    $account = makeAccountForTypeTest();
    $transfers = Category::create(['name' => 'Transfers']);
    $internal = Category::create(['name' => 'Internal transfers', 'parent_id' => $transfers->id]);

    $originalCategory = OriginalCategory::create(['name' => 'Restaurants', 'pf_primary' => 'FOOD_AND_DRINK', 'pf_detailed' => 'FOOD_AND_DRINK_RESTAURANT']);

    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Misclassified transfer',
        'amount' => -50,
        'currency' => 'USD',
        'original_category_id' => $originalCategory->id,
    ]);
    $transaction->categories()->sync([$internal->id]);

    $transaction->refreshType();

    expect($transaction->fresh()->type)->toBe('transfer');
});

it('refreshType does not require a "Transfers" category to exist in this install', function (): void {
    $account = makeAccountForTypeTest();

    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Groceries',
        'amount' => -50,
        'currency' => 'USD',
    ]);

    $transaction->refreshType();

    expect($transaction->fresh()->type)->toBe('expense');
});

// --- Ingest-time classification (UpdateAccountTransactionsAction) ---

it('sets type=transfer at ingest when Plaid classifies the transaction as TRANSFER_IN', function (): void {
    $account = makeAccountForTypeTest(['plaid_account_id' => 'plaid_checking_1']);

    UpdateAccountTransactionsAction::run(plaidTransactionPayload([
        'account_id' => 'plaid_checking_1',
        'amount' => -100, // Plaid raw positive=out; negative=in, so this is money arriving
        'category' => ['Transfer', 'Deposit'],
        'personal_finance_category' => ['primary' => 'TRANSFER_IN', 'detailed' => 'TRANSFER_IN_ACCOUNT_TRANSFER'],
    ]), 'added');

    $transaction = Transaction::where('account_id', $account->id)->firstOrFail();
    expect($transaction->type)->toBe('transfer');
});

it('sets type=expense at ingest for an ordinary purchase', function (): void {
    $account = makeAccountForTypeTest(['plaid_account_id' => 'plaid_checking_2']);

    UpdateAccountTransactionsAction::run(plaidTransactionPayload([
        'account_id' => 'plaid_checking_2',
        'amount' => 25, // Plaid raw positive = money out
        'category' => ['Food and Drink', 'Restaurants'],
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT'],
    ]), 'added');

    $transaction = Transaction::where('account_id', $account->id)->firstOrFail();
    expect($transaction->type)->toBe('expense');
    expect((float) $transaction->amount)->toBeLessThan(0);
});

it('sets type=transfer at ingest for a credit-card payment even though Plaid buckets it under LOAN_PAYMENTS', function (): void {
    $account = makeAccountForTypeTest(['plaid_account_id' => 'plaid_card_1', 'type' => 'credit', 'subtype' => 'credit card']);

    UpdateAccountTransactionsAction::run(plaidTransactionPayload([
        'account_id' => 'plaid_card_1',
        'amount' => -150,
        'category' => ['Payment', 'Credit Card'],
        'personal_finance_category' => ['primary' => 'LOAN_PAYMENTS', 'detailed' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT'],
    ]), 'added');

    $transaction = Transaction::where('account_id', $account->id)->firstOrFail();
    expect($transaction->type)->toBe('transfer');
});

// --- scopeReportable() ---

it('reportable() excludes transfers and adjustments but includes income and expense', function (): void {
    $account = makeAccountForTypeTest();

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 1000, 'currency' => 'USD', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'To Savings', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer']);
    Transaction::factory()->for($account)->create(['name' => 'Opening Balance', 'amount' => 500, 'currency' => 'USD', 'type' => 'adjustment']);

    expect(Transaction::reportable()->count())->toBe(2);
    expect(Transaction::reportable()->pluck('name')->sort()->values()->all())->toBe(['Groceries', 'Paycheck']);
});

it('reportable() still includes a transaction with no type set yet, rather than silently hiding it', function (): void {
    // SQL's `NOT IN` excludes NULLs by default — an un-backfilled or newly-created transaction
    // must not vanish from every report just because it hasn't been classified yet.
    $account = makeAccountForTypeTest();

    Transaction::factory()->for($account)->create(['name' => 'Unclassified', 'amount' => -30, 'currency' => 'USD']);

    expect(Transaction::reportable()->count())->toBe(1);
});
