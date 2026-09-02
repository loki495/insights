<?php

declare(strict_types=1);

use App\Console\Commands\BackfillTransactionTypes;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeBackfillTestAccount(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);
}

it('classifies every transaction\'s type and reports the counts', function (): void {
    $account = makeBackfillTestAccount();
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 200, 'currency' => 'USD', 'type' => null]);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => null]);

    $this->artisan(BackfillTransactionTypes::class)
        ->expectsOutputToContain('Type classification complete:')
        ->expectsOutputToContain('income: 1')
        ->expectsOutputToContain('expense: 1')
        ->assertSuccessful();

    expect(Transaction::where('name', 'Paycheck')->value('type'))->toBe('income')
        ->and(Transaction::where('name', 'Groceries')->value('type'))->toBe('expense');
});

it('also runs transfer-pair matching and reports the summary', function (): void {
    // Tagged with the "Transfers" category so refreshType()'s category-based override keeps
    // them classified as transfers — otherwise the command's own type-backfill pass (which runs
    // before pair-matching) would reclassify these by amount sign alone (income/expense) before
    // MatchTransferPairsAction ever sees them, since neither has a Plaid original category.
    $transfers = Category::create(['name' => 'Transfers']);
    $checking = makeBackfillTestAccount();
    $card = makeBackfillTestAccount();

    $out = Transaction::factory()->for($checking)->create([
        'name' => 'Card payment', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $in = Transaction::factory()->for($card)->create([
        'name' => 'Payment received', 'amount' => 200, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $out->categories()->sync([$transfers->id]);
    $in->categories()->sync([$transfers->id]);

    $this->artisan(BackfillTransactionTypes::class)
        ->expectsOutputToContain('Transfer pairing complete:')
        ->expectsOutputToContain('matched pairs: 1')
        ->expectsOutputToContain('unpaired: 0')
        ->assertSuccessful();

    expect($out->fresh()->transfer_pair_id)->toBe($in->id)
        ->and($in->fresh()->transfer_pair_id)->toBe($out->id);
});
