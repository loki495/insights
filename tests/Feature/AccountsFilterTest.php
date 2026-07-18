<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForFilterTest(User $user, string $name): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => $name,
        'official_name' => $name.' Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);
}

it('account_ids empty shows transactions from every account the user owns', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $checking = makeAccountForFilterTest($user, 'Checking');
    $savings = makeAccountForFilterTest($user, 'Savings');

    Transaction::factory()->for($checking)->create(['name' => 'Txn A', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($savings)->create(['name' => 'Txn B', 'amount' => -20, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['allow_accounts' => true]);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(2);
});

it('account_ids filters transactions down to only the selected accounts', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $checking = makeAccountForFilterTest($user, 'Checking');
    $savings = makeAccountForFilterTest($user, 'Savings');
    $credit = makeAccountForFilterTest($user, 'Credit Card');

    Transaction::factory()->for($checking)->create(['name' => 'Txn A', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($savings)->create(['name' => 'Txn B', 'amount' => -20, 'currency' => 'USD']);
    Transaction::factory()->for($credit)->create(['name' => 'Txn C', 'amount' => -30, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['allow_accounts' => true]);
    $test->set('account_ids', [$checking->id, $savings->id]);

    expect($test->instance()->getTransactionsQuery()->pluck('name')->all())
        ->toEqualCanonicalizing(['Txn A', 'Txn B']);
});

it('account_ids cannot be used to leak another user\'s account transactions', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $ownAccount = makeAccountForFilterTest($user, 'Checking');
    Transaction::factory()->for($ownAccount)->create(['name' => 'Mine', 'amount' => -10, 'currency' => 'USD']);

    $otherUser = User::factory()->create();
    $otherAccount = makeAccountForFilterTest($otherUser, 'Other Checking');
    Transaction::factory()->for($otherAccount)->create(['name' => 'Not Mine', 'amount' => -10, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['allow_accounts' => true]);
    // Maliciously (or accidentally) request an account_id belonging to another user.
    $test->set('account_ids', [$ownAccount->id, $otherAccount->id]);

    expect($test->instance()->getTransactionsQuery()->pluck('name')->all())->toBe(['Mine']);
});
