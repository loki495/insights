<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Curl\CurlRequestException;
use App\Services\Plaid\PlaidService;

/**
 * Plaid's /transactions/sync is cursor-based: each response's next_cursor is where the
 * following pull should resume. Before this was persisted, every pull started from null
 * and refetched the whole days_requested window - and because `removed` events are
 * cursor-relative, deletions could be missed entirely.
 */
function makeLinkedAccountForCursorTest(?string $cursor = null): LinkedAccount
{
    return LinkedAccount::factory()->for(User::factory()->create())->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'transactions_cursor' => $cursor,
    ]);
}

/**
 * Local rather than reusing PullLinkedAccountTransactionsActionTest's fakePullingPlaid():
 * that one is a plain global function defined in another test file, so depending on it
 * here would make this file's outcome depend on suite load order.
 */
function fakeCursorPlaid(callable $expectations): void
{
    $mock = Mockery::mock(PlaidService::class);
    $real = new PlaidService(PlaidService::ENV_SANDBOX, 'test-client-id');
    $mock->shouldReceive('resolveCategory')->andReturnUsing(fn (array $info): ?\App\Models\OriginalCategory => $real->resolveCategory($info));
    $mock->shouldReceive('getItemAccounts')->once()->andReturn(['accounts' => []]);
    $expectations($mock);
    app()->bind(PlaidService::class, fn () => $mock);
}

it('stores the next_cursor returned by the final page', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest();

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => false,
                'next_cursor' => 'cursor_after_first_sync',
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->transactions_cursor)->toBe('cursor_after_first_sync');
});

it('resumes from the stored cursor instead of refetching from the beginning', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest('cursor_from_last_run');

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ($data['cursor'] ?? null) === 'cursor_from_last_run')
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => false,
                'next_cursor' => 'cursor_after_second_sync',
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->transactions_cursor)->toBe('cursor_after_second_sync');
});

it('sends no cursor at all when none is stored yet', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest();

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ! isset($data['cursor']))
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => false, 'next_cursor' => 'first_cursor',
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->transactions_cursor)->toBe('first_cursor');
});

it('advances the cursor only once the final page is reached, not per page', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest();

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ! isset($data['cursor']))
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => true, 'next_cursor' => 'intermediate_page_cursor',
            ]);

        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ($data['cursor'] ?? null) === 'intermediate_page_cursor')
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => false, 'next_cursor' => 'final_page_cursor',
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->transactions_cursor)->toBe('final_page_cursor');
});

/**
 * The important safety property: advancing past a page whose events were never applied
 * would lose them permanently, since Plaid never replays them at a later cursor.
 */
it('leaves the stored cursor untouched when a later page fails mid-pagination', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest('cursor_from_last_run');

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ($data['cursor'] ?? null) === 'cursor_from_last_run')
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => true, 'next_cursor' => 'page_that_will_fail',
            ]);

        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->withArgs(fn (array $data): bool => ($data['cursor'] ?? null) === 'page_that_will_fail')
            ->andThrow(new CurlRequestException('INTERNAL_SERVER_ERROR', 500));
    });

    expect(fn () => PullLinkedAccountTransactionsAction::run($linkedAccount))
        ->toThrow(CurlRequestException::class);

    $linkedAccount->refresh();

    expect($linkedAccount->transactions_cursor)->toBe('cursor_from_last_run')
        ->and($linkedAccount->last_sync_failed_at)->not->toBeNull();
});

it('keeps the existing cursor when the final page omits next_cursor entirely', function (): void {
    $linkedAccount = makeLinkedAccountForCursorTest('cursor_from_last_run');

    fakeCursorPlaid(function ($mock): void {
        $mock->shouldReceive('getItemTransactions')
            ->once()
            ->andReturn([
                'accounts' => [], 'added' => [], 'modified' => [], 'removed' => [],
                'has_more' => false,
            ]);
    });

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    expect($linkedAccount->fresh()->transactions_cursor)->toBe('cursor_from_last_run');
});
