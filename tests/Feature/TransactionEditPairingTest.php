<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountsForPairingTest(): array
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    $checking = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);
    $card = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '1111', 'name' => 'Card',
        'official_name' => 'Card Official', 'type' => 'credit', 'subtype' => 'credit card',
    ]);

    test()->actingAs($user);

    return [$checking, $card];
}

it('shows a search-to-pair control for an unpaired transfer and pairs it on selection', function (): void {
    [$checking, $card] = makeAccountsForPairingTest();

    $transaction = Transaction::factory()->for($checking)->create([
        'name' => 'Card payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $other = Transaction::factory()->for($card)->create([
        'name' => 'Payment received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);

    $test = Livewire::test('admin.transactions.edit', ['transaction' => $transaction]);
    $test->set('pair_search', 'Payment received');

    expect($test->instance()->pairCandidates->pluck('id')->all())->toBe([$other->id]);

    $test->call('pairWith', $other->id);

    expect($transaction->fresh()->transfer_pair_id)->toBe($other->id);
    expect($other->fresh()->transfer_pair_id)->toBe($transaction->id);
});

it('refuses to pair two transactions from the same account', function (): void {
    [$checking] = makeAccountsForPairingTest();

    $transaction = Transaction::factory()->for($checking)->create([
        'name' => 'Leg A', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $sameAccountLeg = Transaction::factory()->for($checking)->create([
        'name' => 'Leg B', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);

    $test = Livewire::test('admin.transactions.edit', ['transaction' => $transaction]);

    expect(fn () => $test->call('pairWith', $sameAccountLeg->id))
        ->toThrow(InvalidArgumentException::class);
});

it('unpairs both legs of a matched transfer', function (): void {
    [$checking, $card] = makeAccountsForPairingTest();

    $transaction = Transaction::factory()->for($checking)->create([
        'name' => 'Card payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $other = Transaction::factory()->for($card)->create([
        'name' => 'Payment received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $transaction->update(['transfer_pair_id' => $other->id]);
    $other->update(['transfer_pair_id' => $transaction->id]);

    $test = Livewire::test('admin.transactions.edit', ['transaction' => $transaction]);
    $test->call('unpair');

    expect($transaction->fresh()->transfer_pair_id)->toBeNull();
    expect($other->fresh()->transfer_pair_id)->toBeNull();
});
