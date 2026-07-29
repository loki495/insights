<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Services\Curl\CurlRequestException;
use App\Services\Plaid\PlaidService;

/**
 * plaid()/PlaidService is always resolved via app(PlaidService::class, ['environment' => ...]) —
 * a non-empty $parameters array, which skips any instance()-bound mock. A plain bind() goes
 * through the normal concrete-resolution path regardless, so it works here. Matches the existing
 * convention in tests/Feature/PlaidLinkFlowTest.php.
 */
function fakeFailingPlaid(Throwable $exception): void
{
    $mock = Mockery::mock(PlaidService::class);
    $mock->shouldReceive('getItemTransactions')->andThrow($exception);
    app()->bind(PlaidService::class, fn () => $mock);
}

it('records the failure on the LinkedAccount and rethrows when a Plaid pull fails', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
    ]);

    fakeFailingPlaid(new CurlRequestException('ITEM_LOGIN_REQUIRED', 400, ['error_code' => 'ITEM_LOGIN_REQUIRED']));

    expect(fn () => PullLinkedAccountTransactionsAction::run($linkedAccount))
        ->toThrow(CurlRequestException::class);

    $linkedAccount->refresh();
    expect($linkedAccount->last_sync_failed_at)->not->toBeNull()
        ->and($linkedAccount->last_sync_error)->toBe('ITEM_LOGIN_REQUIRED')
        ->and($linkedAccount->last_pulled_at)->toBeNull();
});

it('clears a previously recorded failure once a pull succeeds', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1', 'access_token' => 'token_1',
        'last_sync_failed_at' => now()->subDay(),
        'last_sync_error' => 'a previous failure',
    ]);

    $mock = Mockery::mock(PlaidService::class);
    $mock->shouldReceive('getItemTransactions')->once()->andReturn([
        'accounts' => [], 'added' => [], 'removed' => [], 'modified' => [], 'has_more' => false,
    ]);
    app()->bind(PlaidService::class, fn () => $mock);

    PullLinkedAccountTransactionsAction::run($linkedAccount);

    $linkedAccount->refresh();
    expect($linkedAccount->last_sync_failed_at)->toBeNull()
        ->and($linkedAccount->last_sync_error)->toBeNull()
        ->and($linkedAccount->last_pulled_at)->not->toBeNull();
});

it('does not let one failing institution block the others in the scheduled command', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $failing = LinkedAccount::factory()->for($userA)->create([
        'item_id' => 'item_fail', 'access_token' => 'token_fail', 'auto_pull_enabled' => true,
    ]);
    $succeeding = LinkedAccount::factory()->for($userB)->create([
        'item_id' => 'item_ok', 'access_token' => 'token_ok', 'auto_pull_enabled' => true,
    ]);

    $mock = Mockery::mock(PlaidService::class);
    $mock->shouldReceive('getItemTransactions')
        ->withArgs(fn (array $data): bool => $data['access_token'] === 'token_fail')
        ->andThrow(new CurlRequestException('ITEM_LOGIN_REQUIRED', 400));
    $mock->shouldReceive('getItemTransactions')
        ->withArgs(fn (array $data): bool => $data['access_token'] === 'token_ok')
        ->andReturn(['accounts' => [], 'added' => [], 'removed' => [], 'modified' => [], 'has_more' => false]);
    app()->bind(PlaidService::class, fn () => $mock);

    $this->artisan('transactions:pull')->assertExitCode(1);

    expect($failing->fresh()->last_sync_failed_at)->not->toBeNull()
        ->and($succeeding->fresh()->last_pulled_at)->not->toBeNull();
});
