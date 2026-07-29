<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

it('resolves the transactions relation, matching the transaction_edit page\'s eager load', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
    $category = Category::create(['name' => 'Groceries']);
    $txn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -10, 'currency' => 'USD']);
    $txn->categories()->sync([$category->id]);

    $loaded = Category::with('transactions')->find($category->id);

    expect($loaded->transactions)->toHaveCount(1)
        ->and($loaded->transactions->first()->is($txn))->toBeTrue();
});
