<?php

declare(strict_types=1);

use App\Actions\Models\CategoryRule\CreateCategoryRule;
use App\Actions\Models\CategoryRule\DeleteCategoryRule;
use App\Actions\Models\CategoryRule\UpdateCategoryRule;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\CategoryRuleCondition;
use App\Models\CategoryRuleConditionGroup;
use App\Models\User;

it('creates a rule with its groups and conditions, appending to the end of this user\'s priority order', function (): void {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $existing = CategoryRule::factory()->for($user)->create(['priority' => 3]);

    $rule = CreateCategoryRule::run($user, $category->id, 'My Rule', 'any', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks', 'value_end' => null],
            ['field' => 'amount', 'operator' => 'less_than', 'value' => '10', 'value_end' => null],
        ]],
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'amount', 'operator' => 'greater_than', 'value' => '1000', 'value_end' => null],
        ]],
    ]);

    expect($rule->user_id)->toBe($user->id)
        ->and($rule->category_id)->toBe($category->id)
        ->and($rule->name)->toBe('My Rule')
        ->and($rule->match_type)->toBe('any')
        ->and($rule->priority)->toBe(4)
        ->and($rule->active)->toBeTrue()
        ->and($rule->conditionGroups)->toHaveCount(2)
        ->and($rule->conditionGroups->first()->conditions)->toHaveCount(2)
        ->and($rule->conditionGroups->last()->conditions)->toHaveCount(1)
        ->and($existing->fresh()->priority)->toBe(3);
});

it('defaults the first rule\'s priority to 0', function (): void {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $rule = CreateCategoryRule::run($user, $category->id, null, 'all', []);

    expect($rule->priority)->toBe(0);
});

it('replaces all groups/conditions on update rather than merging them', function (): void {
    $user = User::factory()->create();
    $original = Category::factory()->create();
    $replacement = Category::factory()->create();
    $rule = CreateCategoryRule::run($user, $original->id, 'Original', 'all', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks', 'value_end' => null],
        ]],
    ]);

    UpdateCategoryRule::run($rule, $replacement->id, 'Updated', 'any', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'amount', 'operator' => 'greater_than', 'value' => '100', 'value_end' => null],
        ]],
    ]);

    $rule->refresh();
    expect($rule->category_id)->toBe($replacement->id)
        ->and($rule->name)->toBe('Updated')
        ->and($rule->match_type)->toBe('any')
        ->and($rule->conditionGroups)->toHaveCount(1)
        ->and($rule->conditionGroups->first()->conditions->first()->field)->toBe('amount');
});

it('deletes a rule and cascades its groups and conditions', function (): void {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $rule = CreateCategoryRule::run($user, $category->id, null, 'all', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'starbucks', 'value_end' => null],
        ]],
    ]);
    $groupId = $rule->conditionGroups->first()->id;
    $conditionId = $rule->conditionGroups->first()->conditions->first()->id;

    DeleteCategoryRule::run($rule);

    expect(CategoryRule::find($rule->id))->toBeNull()
        ->and(CategoryRuleConditionGroup::find($groupId))->toBeNull()
        ->and(CategoryRuleCondition::find($conditionId))->toBeNull();
});
