<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\CategoryRuleConditionGroup;
use App\Models\User;

it('condition belongs to its group, and group belongs to its rule', function (): void {
    $rule = CategoryRule::factory()->for(User::factory())->for(Category::factory())->create();
    $group = $rule->conditionGroups()->create(['match_type' => 'all', 'position' => 0]);
    $condition = $group->conditions()->create(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks']);

    expect($condition->conditionGroup)->toBeInstanceOf(CategoryRuleConditionGroup::class)
        ->and($condition->conditionGroup->id)->toBe($group->id)
        ->and($group->categoryRule)->toBeInstanceOf(CategoryRule::class)
        ->and($group->categoryRule->id)->toBe($rule->id);
});
