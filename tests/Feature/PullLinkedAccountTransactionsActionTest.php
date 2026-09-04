<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Enums\AccountDisabledReason;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Plaid\PlaidService;

/**
 * plaid()/PlaidService is always resolved via app(PlaidService::class, ['environment' => ...]) —
 * a non-empty $parameters array, which skips any instance()-bound mock. A plain bind() goes
 * through the normal concrete-resolution path regardless, so it works here. Matches the existing
 * convention in tests/Feature/PlaidLinkFlowTest.php and PullSyncFailureTest.php.
 */
function fakePullingPlaid(callable $expectations): void
{
    $mock = Mockery::mock(PlaidService::class);
    // resolveCategory() has real (non-network) logic that UpdateAccountTransactionsAction
    // depends on for classifying transfer types — delegate to a real instance built directly
    // (bypassing the container, which is about to be rebound to this same mock) rather than
    // stubbing it out.
    $real = new PlaidService(PlaidService::ENV_SANDBOX, 'test-client-id');
    $mock->shouldReceive('resolveCategory')->andReturnUsing(fn (array $info): ?\App\Models\OriginalCategory => $real->resolveCategory($info));
    $mock->shouldReceive('getItemAccounts')->byDefault()->andReturn([
        'accounts' => [plaidAccountPayload()],
    ]);
    $expectations($mock);
    app()->bind(PlaidService::class, fn () => $mock);
}

function plaidAccountPayload(array $overrides = []): array
{
    return array_merge([
        'account_id' => 'plaid_checking_1',
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
        'balances' => ['iso_currency_code' => 'USD', 'available' => 100.0, 'current' => 100.0, 'limit' => null],
    ], $overrides);
}

function plaidPullTransactionPayload(array $overrides = []): array
{
    return array_merge([
        'transaction_id' => 'txn_1',
        'account_id' => 'plaid_checking_1',
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

it('runs the full added/modified/removed happy path across a two-page pull, then reconciles and matches transfers', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);

    // Pre-existing state the pull will modify: an account whose current_balance the pull's
    // page-1 response will update, and two pre-existing transactions — one "modified" and one
    // "removed" by the pull.
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_checking_1', 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
        'current_balance' => 0,
    ]);
    $toBeModified = Transaction::factory()->for($account)->create([
        'transaction_id' => 'txn_modify_me', 'name' => 'Old name', 'amount' => -5, 'currency' => 'USD',
    ]);
    $toBeRemoved = Transaction::factory()->for($account)->create([
        'transaction_id' => 'txn_remove_me', 'name' => 'Gone soon', 'amount' => -1, 'currency' => 'USD',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemAccounts')->once()->andReturn([
            'accounts' => [plaidAccountPayload([
                'balances' => ['iso_currency_code' => 'USD', 'available' => 150.0, 'current' => 200.0, 'limit' => null],
            ])],
        ]);
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ! isset($data['cursor']))
            ->andReturn([
                'accounts' => [plaidAccountPayload(['balances' => ['iso_currency_code' => 'USD', 'available' => 150.0, 'current' => 200.0, 'limit' => null]])],
                'added' => [plaidPullTransactionPayload(['transaction_id' => 'txn_new', 'name' => 'New purchase', 'amount' => 25.0])],
                'modified' => [plaidPullTransactionPayload(['transaction_id' => 'txn_modify_me', 'name' => 'Corrected name', 'amount' => 5.0])],
                'removed' => [],
                'has_more' => true,
                'next_cursor' => 'cursor_page_2',
            ]);

        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ($data['cursor'] ?? null) === 'cursor_page_2')
            ->andReturn([
                'accounts' => [],
                'added' => [],
                'modified' => [],
                'removed' => [['transaction_id' => 'txn_remove_me']],
                'has_more' => false,
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    // Account balances updated from page 1's accounts payload.
    $account->refresh();
    expect($account->available_balance)->toEqual(150.0)
        ->and($account->current_balance)->toEqual(200.0);

    // "added" from page 1 created a new transaction.
    expect(Transaction::where('transaction_id', 'txn_new')->exists())->toBeTrue();

    // "modified" from page 1 updated the existing transaction in place, not duplicated.
    expect(Transaction::where('transaction_id', 'txn_modify_me')->count())->toBe(1);
    expect($toBeModified->fresh()->name)->toBe('Corrected name');

    // "removed" from page 2 deleted the pre-existing transaction.
    expect(Transaction::where('transaction_id', 'txn_remove_me')->exists())->toBeFalse();
    unset($toBeRemoved);

    // Reconcile ran after the last page: every remaining transaction on the account has a
    // running_balance walked back from the final current_balance.
    expect(Transaction::where('transaction_id', 'txn_new')->first()->running_balance)->not->toBeNull();

    // last_pulled_at is only set once, after the whole (both-page) pull completes.
    $linkedAccount->refresh();
    expect($linkedAccount->last_pulled_at)->not->toBeNull();
});

it('matches transfer-pair transactions across accounts as part of the same pull', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);
    $checking = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_checking_1', 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    $card = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_card_1', 'mask' => '1111', 'name' => 'Card',
        'official_name' => 'Card Official', 'type' => 'credit', 'subtype' => 'credit card',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')->once()->andReturn([
            'accounts' => [
                plaidAccountPayload(['account_id' => 'plaid_checking_1', 'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official']),
                plaidAccountPayload(['account_id' => 'plaid_card_1', 'mask' => '1111', 'name' => 'Card', 'official_name' => 'Card Official', 'type' => 'credit', 'subtype' => 'credit card']),
            ],
            'added' => [
                plaidPullTransactionPayload([
                    'transaction_id' => 'txn_transfer_out', 'account_id' => 'plaid_checking_1',
                    'name' => 'Card payment', 'amount' => 200.0,
                    'category' => ['Transfer', 'Credit Card Payment'], 'category_id' => '21009000',
                    'personal_finance_category' => ['primary' => 'TRANSFER_OUT', 'detailed' => 'TRANSFER_OUT_ACCOUNT_TRANSFER'],
                ]),
                plaidPullTransactionPayload([
                    'transaction_id' => 'txn_transfer_in', 'account_id' => 'plaid_card_1',
                    'name' => 'Payment received', 'amount' => -200.0,
                    'category' => ['Transfer', 'Credit Card Payment'], 'category_id' => '21009000',
                    'personal_finance_category' => ['primary' => 'TRANSFER_IN', 'detailed' => 'TRANSFER_IN_ACCOUNT_TRANSFER'],
                ]),
            ],
            'modified' => [],
            'removed' => [],
            'has_more' => false,
        ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    $out = Transaction::where('transaction_id', 'txn_transfer_out')->firstOrFail();
    $in = Transaction::where('transaction_id', 'txn_transfer_in')->firstOrFail();

    expect($out->type)->toBe('transfer')
        ->and($in->type)->toBe('transfer')
        ->and($out->transfer_pair_id)->toBe($in->id)
        ->and($in->transfer_pair_id)->toBe($out->id)
        ->and($checking->fresh())->not->toBeNull()
        ->and($card->fresh())->not->toBeNull();
});

it('does not call Plaid at all for a closed linked account', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1', 'closed_at' => now(),
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldNotReceive('getItemTransactions');
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->last_pulled_at)->toBeNull();
});

it('does not call Plaid at all for a demo linked account', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1', 'is_demo' => true,
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldNotReceive('getItemTransactions');
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->last_pulled_at)->toBeNull();
});

it('disables accounts omitted from the latest successful account snapshot', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);
    $activeAccount = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_checking_1', 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    $missingAccount = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_missing', 'mask' => '9999', 'name' => 'Old Account',
        'official_name' => 'Old Account', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    $historicalTransaction = Transaction::factory()->for($missingAccount)->create([
        'transaction_id' => 'historical_txn', 'name' => 'Historical transaction',
        'amount' => -10, 'currency' => 'USD',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')->once()->andReturn([
            'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
            'has_more' => false, 'next_cursor' => 'cursor_1',
        ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($activeAccount->fresh()->disabled_at)->toBeNull()
        ->and($missingAccount->fresh()->disabled_at)->not->toBeNull()
        ->and($historicalTransaction->fresh()->account->is($missingAccount))->toBeTrue();
});

it('restores a disabled account when it returns in a later account snapshot', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_checking_1', 'disabled_at' => now(),
        'disabled_reason' => AccountDisabledReason::MissingFromProvider, 'mask' => '0000',
        'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')->once()->andReturn([
            'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
            'has_more' => false, 'next_cursor' => 'cursor_1',
        ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($account->fresh()->disabled_at)->toBeNull();
});

it('does not restore a manually disabled account during a normal pull', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_checking_1', 'disabled_at' => now(),
        'disabled_reason' => AccountDisabledReason::Manual, 'mask' => '0000',
        'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')->once()->andReturn([
            'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
            'has_more' => false, 'next_cursor' => 'cursor_1',
        ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($account->fresh()->disabled_at)->not->toBeNull()
        ->and($account->fresh()->disabled_reason)->toBe(AccountDisabledReason::Manual);
});

it('does not disable missing accounts when the transaction pull fails', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);
    $missingAccount = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_missing', 'mask' => '9999', 'name' => 'Old Account',
        'official_name' => 'Old Account', 'type' => 'depository', 'subtype' => 'checking',
    ]);

    fakePullingPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')->once()->andThrow(new RuntimeException('Plaid unavailable'));
    });

    expect(fn () => PullLinkedAccountTransactionsAction::run($linkedAccount))
        ->toThrow(RuntimeException::class, 'Plaid unavailable')
        ->and($missingAccount->fresh()->disabled_at)->toBeNull();
});
