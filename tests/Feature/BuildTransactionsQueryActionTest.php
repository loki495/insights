<?php

declare(strict_types=1);

use App\Actions\BuildTransactionsQueryAction;
use App\Actions\TransactionFilters;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Covers the search-term syntax documented in the README: bare words (or a redundant
 * `+`-prefixed word) are required — every one must match — and `-`-prefixed words are excluded.
 * Previously exercised only by a single incidental single-bare-word test elsewhere, with
 * multi-word/mixed-term behavior and parseSearch()'s prefix parsing completely untested — which
 * also let a real bug ship: bare words used to be OR'd across the whole query (an "optional"
 * bucket) rather than combined with everything else, so e.g. "+amazon +prime -refund order"
 * would match a transaction missing "amazon" as long as it contained "order".
 */
function makeSearchTestAccount(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'tracking_mode' => 'tracked',
    ]);
}

function runSearchQuery(User $user, string $search): Collection
{
    $filters = new TransactionFilters(dateFrom: '2020-01-01', dateTo: '2030-01-01', search: $search);

    return BuildTransactionsQueryAction::run($user, $filters)->get();
}

it('requires every bare word to match (AND, not OR)', function (): void {
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $both = Transaction::factory()->for($account)->create(['name' => 'Blue Bottle Coffee', 'amount' => -5, 'currency' => 'USD']);
    $coffeeOnly = Transaction::factory()->for($account)->create(['name' => 'Coffee Roasters', 'amount' => -6, 'currency' => 'USD']);
    $blueOnly = Transaction::factory()->for($account)->create(['name' => 'Blue Sky Diner', 'amount' => -7, 'currency' => 'USD']);

    $results = runSearchQuery($user, 'blue coffee');

    expect($results->pluck('id'))->toContain($both->id);
    expect($results->pluck('id'))->not->toContain($coffeeOnly->id);
    expect($results->pluck('id'))->not->toContain($blueOnly->id);
});

it('treats a redundant +prefix the same as a bare word', function (): void {
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $both = Transaction::factory()->for($account)->create(['name' => 'Blue Bottle Coffee', 'amount' => -5, 'currency' => 'USD']);
    $coffeeOnly = Transaction::factory()->for($account)->create(['name' => 'Coffee Roasters', 'amount' => -6, 'currency' => 'USD']);

    $results = runSearchQuery($user, '+blue +coffee');

    expect($results->pluck('id'))->toContain($both->id);
    expect($results->pluck('id'))->not->toContain($coffeeOnly->id);
});

it('excludes a transaction matching a -prefixed term even if it matches the required terms', function (): void {
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $keep = Transaction::factory()->for($account)->create(['name' => 'Coffee Shop', 'amount' => -5, 'currency' => 'USD']);
    $excluded = Transaction::factory()->for($account)->create(['name' => 'Coffee Shop Refund', 'amount' => 5, 'currency' => 'USD']);

    $results = runSearchQuery($user, 'coffee -refund');

    expect($results->pluck('id'))->toContain($keep->id);
    expect($results->pluck('id'))->not->toContain($excluded->id);
});

it('matches on merchant_name too, not just name', function (): void {
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $txn = Transaction::factory()->for($account)->create([
        'name' => 'Purchase', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD',
    ]);

    $results = runSearchQuery($user, 'wholefoods');
    expect($results->pluck('id'))->not->toContain($txn->id);

    $results = runSearchQuery($user, 'whole');
    expect($results->pluck('id'))->toContain($txn->id);
});

it('excludes a transaction with no merchant_name at all when filtering it out by merchant term', function (): void {
    // Regression guard for the excluded-term branch's explicit orWhereNull('merchant_name') —
    // without it, a transaction with a null merchant_name would incorrectly get excluded by
    // *any* merchant-based exclusion term, since `NULL NOT LIKE '%x%'` is NULL (falsy) in SQL.
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $txn = Transaction::factory()->for($account)->create([
        'name' => 'Grocery Run', 'merchant_name' => null, 'amount' => -20, 'currency' => 'USD',
    ]);

    $results = runSearchQuery($user, 'grocery -somethingelse');

    expect($results->pluck('id'))->toContain($txn->id);
});

it('requires every term across a mix of bare, +prefixed, and -excluded terms, without excluded/other terms leaking past required ones', function (): void {
    $user = User::factory()->create();
    $account = makeSearchTestAccount($user);
    $match = Transaction::factory()->for($account)->create(['name' => 'Amazon Prime Order', 'amount' => -15, 'currency' => 'USD']);
    $missingRequired = Transaction::factory()->for($account)->create(['name' => 'Prime Order', 'amount' => -15, 'currency' => 'USD']);
    $hasExcluded = Transaction::factory()->for($account)->create(['name' => 'Amazon Prime Order Refund', 'amount' => 15, 'currency' => 'USD']);

    $results = runSearchQuery($user, '+amazon +prime -refund order');

    expect($results->pluck('id'))->toContain($match->id);
    expect($results->pluck('id'))->not->toContain($missingRequired->id);
    expect($results->pluck('id'))->not->toContain($hasExcluded->id);
});
