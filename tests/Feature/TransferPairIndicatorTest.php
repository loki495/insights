<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForTransferPairIndicatorTest(?User $user = null, array $overrides = []): Account
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

it('flags an unpaired transfer with a warning indicator', function (): void {
    $account = makeAccountForTransferPairIndicatorTest();
    Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml('Unpaired transfer');
    $test->assertDontSeeHtml('Paired transfer');
});

it('marks a paired transfer as paired instead of unpaired', function (): void {
    $account = makeAccountForTransferPairIndicatorTest();
    $otherAccount = makeAccountForTransferPairIndicatorTest($account->linked_account->user, ['name' => 'Other']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer']);
    $pair = Transaction::factory()->for($otherAccount)->create(['name' => 'Payment Received', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer']);
    $transaction->pairWith($pair);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml('Paired transfer');
    $test->assertDontSeeHtml('Unpaired transfer');
});

it('does not show a pairing indicator for non-transfer transactions', function (): void {
    $account = makeAccountForTransferPairIndicatorTest();
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertDontSeeHtml('Paired transfer');
    $test->assertDontSeeHtml('Unpaired transfer');
});
