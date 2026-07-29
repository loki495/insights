<?php

declare(strict_types=1);

use App\Actions\EditUserCategoryAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForEditUserCategoryActionTest(User $user): Account
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

it('renames a category by finding-or-creating the new name and reassigning the user\'s own transactions', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForEditUserCategoryActionTest($user);
    $old = categoryFor($user, 'Groceries');
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);
    $transaction->categories()->sync([$old->id]);

    $new = EditUserCategoryAction::run($user, $old, null, 'Food', null, '#111111');

    expect($new->id)->not->toBe($old->id)
        ->and($new->name)->toBe('Food')
        ->and($transaction->categories()->pluck('categories.id')->all())->toBe([$new->id])
        ->and($user->categories()->pluck('categories.id')->all())->toBe([$new->id])
        ->and($user->categories()->find($new->id)->pivot->color)->toBe('#111111');
});

it('does not touch another user\'s transactions or adoption of the same shared category', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $accountA = makeAccountForEditUserCategoryActionTest($userA);
    $accountB = makeAccountForEditUserCategoryActionTest($userB);

    $shared = categoryFor($userA, 'Groceries');
    categoryFor($userB, 'Groceries'); // userB adopts the SAME shared row

    $txnA = Transaction::factory()->for($accountA)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);
    $txnA->categories()->sync([$shared->id]);
    $txnB = Transaction::factory()->for($accountB)->create(['name' => 'Store', 'amount' => -30, 'currency' => 'USD']);
    $txnB->categories()->sync([$shared->id]);

    EditUserCategoryAction::run($userA, $shared, null, 'Food', null, null);

    // userB's transaction and adoption of the original "Groceries" category are untouched.
    expect($txnB->fresh()->categories()->pluck('categories.id')->all())->toBe([$shared->id]);
    expect($userB->categories()->pluck('categories.id')->all())->toBe([$shared->id]);
});

it('merging onto an existing category does not create a duplicate pivot row for a transaction already tagged with both', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForEditUserCategoryActionTest($user);
    $old = categoryFor($user, 'Groceries');
    $existing = categoryFor($user, 'Food');

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -20, 'currency' => 'USD']);
    $transaction->categories()->sync([$old->id, $existing->id]);

    $new = EditUserCategoryAction::run($user, $old, null, 'Food', null, null);

    expect($new->id)->toBe($existing->id)
        ->and($transaction->categories()->pluck('categories.id')->all())->toBe([$existing->id]);
});

it('a color-only edit (same name/parent) only updates the pivot, never touches other users', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $category = categoryFor($userA, 'Groceries', color: '#111111');
    categoryFor($userB, 'Groceries', color: '#222222');

    $result = EditUserCategoryAction::run($userA, $category, null, 'Groceries', null, '#999999');

    expect($result->id)->toBe($category->id)
        ->and(Category::count())->toBe(1)
        ->and($userA->categories()->find($category->id)->pivot->color)->toBe('#999999')
        ->and($userB->categories()->find($category->id)->pivot->color)->toBe('#222222');
});

it('updates the shared description when it differs, regardless of rename', function (): void {
    $user = User::factory()->create();
    $category = categoryFor($user, 'Groceries');

    EditUserCategoryAction::run($user, $category, null, 'Groceries', 'Weekly shop', null);

    expect($category->fresh()->description)->toBe('Weekly shop');
});
