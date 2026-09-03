<?php

declare(strict_types=1);

use App\Actions\ApplyCategoryRuleRetroactivelyAction;
use App\Actions\FindMatchingTransactionsForCategoryRuleAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeRetroactiveTestAccount(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
}

/**
 * @param  array{field: string, operator: string, value: string}  $condition
 */
function makeRetroactiveTestRule(User $user, Category $category, array $condition): CategoryRule
{
    $rule = CategoryRule::factory()->for($user)->for($category)->create();
    $group = $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0]);
    $group->conditions()->create($condition);

    return $rule->fresh();
}

it('finds only matching uncategorized transactions belonging to the rule\'s own user', function (): void {
    $user = User::factory()->create();
    $account = makeRetroactiveTestAccount($user);
    $category = Category::factory()->create();
    $rule = makeRetroactiveTestRule($user, $category, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $matching = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);
    $nonMatching = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);
    $alreadyCategorized = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -6, 'currency' => 'USD']);
    $alreadyCategorized->categories()->sync([Category::factory()->create()->id]);

    $stranger = User::factory()->create();
    $strangerAccount = makeRetroactiveTestAccount($stranger);
    Transaction::factory()->for($strangerAccount)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    $result = FindMatchingTransactionsForCategoryRuleAction::run($user, $rule);

    expect($result->pluck('id')->all())->toBe([$matching->id])
        ->and($result->pluck('id'))->not->toContain($nonMatching->id, $alreadyCategorized->id);
});

it('assigns the category to every currently-matching uncategorized transaction and returns the count', function (): void {
    $user = User::factory()->create();
    $account = makeRetroactiveTestAccount($user);
    $category = Category::factory()->create();
    $rule = makeRetroactiveTestRule($user, $category, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $first = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);
    $second = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -6, 'currency' => 'USD']);
    $nonMatching = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    $count = ApplyCategoryRuleRetroactivelyAction::run($rule);

    expect($count)->toBe(2)
        ->and($first->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id])
        ->and($second->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id])
        ->and($nonMatching->fresh()->categories()->count())->toBe(0);
});

it('returns 0 and touches nothing when no transaction currently matches', function (): void {
    $user = User::factory()->create();
    $account = makeRetroactiveTestAccount($user);
    $category = Category::factory()->create();
    $rule = makeRetroactiveTestRule($user, $category, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    $count = ApplyCategoryRuleRetroactivelyAction::run($rule);

    expect($count)->toBe(0);
});

it('never touches another user\'s transactions even if they would otherwise match', function (): void {
    $owner = User::factory()->create();
    $category = Category::factory()->create();
    $rule = makeRetroactiveTestRule($owner, $category, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $stranger = User::factory()->create();
    $strangerAccount = makeRetroactiveTestAccount($stranger);
    $strangerTransaction = Transaction::factory()->for($strangerAccount)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    ApplyCategoryRuleRetroactivelyAction::run($rule);

    expect($strangerTransaction->fresh()->categories()->count())->toBe(0);
});
