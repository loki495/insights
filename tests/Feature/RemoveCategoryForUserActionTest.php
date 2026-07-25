<?php

declare(strict_types=1);

use App\Actions\RemoveCategoryForUserAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForRemoveCategoryTest(User $user): Account
{
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

it('detaches the category from the user\'s own transactions and drops their pivot row', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForRemoveCategoryTest($user);
    $category = categoryFor($user, 'Groceries');
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);
    $transaction->categories()->sync([$category->id]);

    RemoveCategoryForUserAction::run($user, $category);

    expect($transaction->categories()->pluck('categories.id')->all())->toBe([]);
    expect($user->categories()->pluck('categories.id')->all())->toBe([]);
    expect(Category::find($category->id))->not->toBeNull();
});

it('never touches another user\'s transactions or adoption of the same shared category', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountA = makeAccountForRemoveCategoryTest($userA);
    $accountB = makeAccountForRemoveCategoryTest($userB);

    $shared = categoryFor($userA, 'Groceries');
    categoryFor($userB, 'Groceries');

    $txnA = Transaction::factory()->for($accountA)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);
    $txnA->categories()->sync([$shared->id]);
    $txnB = Transaction::factory()->for($accountB)->create(['name' => 'Store', 'amount' => -30, 'currency' => 'USD']);
    $txnB->categories()->sync([$shared->id]);

    RemoveCategoryForUserAction::run($userA, $shared);

    expect($txnB->fresh()->categories()->pluck('categories.id')->all())->toBe([$shared->id]);
    expect($userB->categories()->pluck('categories.id')->all())->toBe([$shared->id]);
});
