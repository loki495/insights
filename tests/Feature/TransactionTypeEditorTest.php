<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

function makeAccountForTypeEditorTest(?User $user = null, array $overrides = []): Account
{
    $user ??= User::factory()->create();

    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    $account = Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Account',
        'official_name' => 'Account Official', 'type' => 'depository', 'subtype' => 'checking',
    ], $overrides));

    test()->actingAs($user);

    return $account;
}

it('typeEditorData returns the current type and no pair for an unpaired transaction', function (): void {
    $account = makeAccountForTypeEditorTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $data = $test->instance()->typeEditorData($transaction->id);

    expect($data['type'])->toBe('expense');
    expect($data['pair'])->toBeNull();
});

it('typeEditorData returns the paired transaction\'s info when one exists', function (): void {
    $account = makeAccountForTypeEditorTest();
    $otherAccount = makeAccountForTypeEditorTest($account->linked_account->user, ['name' => 'Other']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $pair = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);
    $transaction->pairWith($pair);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $data = $test->instance()->typeEditorData($transaction->id);

    expect($data['pair']['id'])->toBe($pair->id);
    expect($data['pair']['label'])->toContain('Payment Received');
});

it('saveType updates the transaction\'s type', function (): void {
    $account = makeAccountForTypeEditorTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $data = $test->instance()->saveType($transaction->id, 'transfer');

    expect($transaction->fresh()->type)->toBe('transfer');
    expect($data['type'])->toBe('transfer');
});

it('saveType clears an existing pair when switching a transaction away from transfer', function (): void {
    $account = makeAccountForTypeEditorTest();
    $otherAccount = makeAccountForTypeEditorTest($account->linked_account->user, ['name' => 'Other']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $pair = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);
    $transaction->pairWith($pair);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $data = $test->instance()->saveType($transaction->id, 'expense');

    expect($transaction->fresh()->transfer_pair_id)->toBeNull();
    expect($pair->fresh()->transfer_pair_id)->toBeNull();
    expect($data['pair'])->toBeNull();
});

it('saveType rejects an invalid type', function (): void {
    $account = makeAccountForTypeEditorTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect(fn () => $test->instance()->saveType($transaction->id, 'bogus'))
        ->toThrow(InvalidArgumentException::class);
});

it('saveType refuses to update another user\'s transaction', function (): void {
    $ownAccount = makeAccountForTypeEditorTest();
    $otherUser = User::factory()->create();
    $otherAccount = makeAccountForTypeEditorTest($otherUser);
    $otherTxn = Transaction::factory()->for($otherAccount)->create(['name' => 'Not Mine', 'amount' => -10, 'currency' => 'USD']);

    test()->actingAs($ownAccount->linked_account->user);

    $test = Livewire::test('components.transactions', ['account' => $ownAccount]);

    expect(fn () => $test->instance()->saveType($otherTxn->id, 'income'))
        ->toThrow(AuthorizationException::class);
});

it('searchTransferPairCandidates finds unpaired transfers from a different account matching the search', function (): void {
    $account = makeAccountForTypeEditorTest();
    $otherAccount = makeAccountForTypeEditorTest($account->linked_account->user, ['name' => 'Other']);
    $sameAccount = $account;

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $goodCandidate = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received Special', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);
    $sameAccountCandidate = Transaction::factory()->for($sameAccount)->create(['name' => 'Payment Received Special 2', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $results = $test->instance()->searchTransferPairCandidates($transaction->id, 'Special');

    expect(collect($results)->pluck('id')->all())->toBe([$goodCandidate->id]);
});

it('pairTransaction pairs two transactions and returns the pair info', function (): void {
    $account = makeAccountForTypeEditorTest();
    $otherAccount = makeAccountForTypeEditorTest($account->linked_account->user, ['name' => 'Other']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $other = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $result = $test->instance()->pairTransaction($transaction->id, $other->id);

    expect($transaction->fresh()->transfer_pair_id)->toBe($other->id);
    expect($other->fresh()->transfer_pair_id)->toBe($transaction->id);
    expect($result['id'])->toBe($other->id);
});

it('pairTransaction refuses to pair two transactions from the same account', function (): void {
    $account = makeAccountForTypeEditorTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Leg A', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $other = Transaction::factory()->for($account)->create(['name' => 'Leg B', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect(fn () => $test->instance()->pairTransaction($transaction->id, $other->id))
        ->toThrow(InvalidArgumentException::class);
});

it('unpairTransaction clears the pairing on both legs', function (): void {
    $account = makeAccountForTypeEditorTest();
    $otherAccount = makeAccountForTypeEditorTest($account->linked_account->user, ['name' => 'Other']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $other = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);
    $transaction->pairWith($other);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->instance()->unpairTransaction($transaction->id);

    expect($transaction->fresh()->transfer_pair_id)->toBeNull();
    expect($other->fresh()->transfer_pair_id)->toBeNull();
});

it('the type pill dispatches edit-type with the transaction id when clicked', function (): void {
    $account = makeAccountForTypeEditorTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml("edit-type', { transaction_id: {$transaction->id} }");
});
