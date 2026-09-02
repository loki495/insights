<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeTransactionRelationTestAccount(): Account
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

it('user() resolves the transaction\'s owning user through account -> linked_account, matching TransactionPolicy\'s own chain', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'amount' => -5, 'currency' => 'USD']);

    expect($transaction->user->id)->toBe($user->id);
});

it('children() and parent() link a split transaction back to its originating parent', function (): void {
    $account = makeTransactionRelationTestAccount();
    $parent = Transaction::factory()->for($account)->create(['name' => 'Costco Trip', 'amount' => -100, 'currency' => 'USD']);
    $child = Transaction::factory()->for($account)->create(['name' => 'Costco Trip (Groceries)', 'amount' => -60, 'currency' => 'USD', 'parent_id' => $parent->id]);

    expect($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->id)->toBe($child->id)
        ->and($child->parent)->toBeInstanceOf(Transaction::class)
        ->and($child->parent->id)->toBe($parent->id);
});
