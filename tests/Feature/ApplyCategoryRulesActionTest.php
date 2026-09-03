<?php

declare(strict_types=1);

use App\Actions\ApplyCategoryRulesAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeApplyRulesTestAccount(User $user): Account
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

function makeRuleFor(User $user, Category $category, string $matchType, int $priority, bool $active = true): CategoryRule
{
    return CategoryRule::factory()->for($user)->for($category)->create([
        'match_type' => $matchType,
        'priority' => $priority,
        'active' => $active,
    ]);
}

/**
 * Wraps a single condition in its own single-condition group — the common case these tests
 * exercise, equivalent to the pre-grouping flat behavior.
 *
 * @param  array<string, mixed>  $condition
 */
function addSimpleCondition(CategoryRule $rule, array $condition): void
{
    $group = $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0]);
    $group->conditions()->create($condition);
}

it('assigns the matching rule\'s category to an uncategorized transaction', function (): void {
    $user = User::factory()->create();
    $account = makeApplyRulesTestAccount($user);
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $rule = makeRuleFor($user, $coffee, 'all', 0);
    addSimpleCondition($rule, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    ApplyCategoryRulesAction::run($transaction);

    expect($transaction->categories()->pluck('categories.id')->all())->toBe([$coffee->id]);
});

it('does not assign anything when no rule matches', function (): void {
    $user = User::factory()->create();
    $account = makeApplyRulesTestAccount($user);
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $rule = makeRuleFor($user, $coffee, 'all', 0);
    addSimpleCondition($rule, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'merchant_name' => 'Whole Foods', 'amount' => -20, 'currency' => 'USD']);

    ApplyCategoryRulesAction::run($transaction);

    expect($transaction->categories()->count())->toBe(0);
});

it('skips an inactive rule even when its conditions would otherwise match', function (): void {
    $user = User::factory()->create();
    $account = makeApplyRulesTestAccount($user);
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $rule = makeRuleFor($user, $coffee, 'all', 0, active: false);
    addSimpleCondition($rule, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    ApplyCategoryRulesAction::run($transaction);

    expect($transaction->categories()->count())->toBe(0);
});

it('picks the lowest-priority matching rule when more than one rule matches', function (): void {
    $user = User::factory()->create();
    $account = makeApplyRulesTestAccount($user);
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $drinks = Category::factory()->create(['name' => 'Drinks']);

    $lowPriority = makeRuleFor($user, $drinks, 'all', 5);
    addSimpleCondition($lowPriority, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star']);

    $highPriority = makeRuleFor($user, $coffee, 'all', 1);
    addSimpleCondition($highPriority, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star']);

    $transaction = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    ApplyCategoryRulesAction::run($transaction);

    expect($transaction->categories()->pluck('categories.id')->all())->toBe([$coffee->id]);
});

it('never applies another user\'s rule', function (): void {
    $owner = User::factory()->create();
    $coffee = Category::factory()->create(['name' => 'Coffee']);
    $rule = makeRuleFor($owner, $coffee, 'all', 0);
    addSimpleCondition($rule, ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    $stranger = User::factory()->create();
    $strangerAccount = makeApplyRulesTestAccount($stranger);
    $transaction = Transaction::factory()->for($strangerAccount)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    ApplyCategoryRulesAction::run($transaction);

    expect($transaction->categories()->count())->toBe(0);
});
