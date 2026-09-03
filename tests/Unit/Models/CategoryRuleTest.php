<?php

declare(strict_types=1);

use App\Models\CategoryRule;
use App\Models\CategoryRuleCondition;
use App\Models\CategoryRuleConditionGroup;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * @param  array<int, array{match_type: string, conditions: array<int, array<string, mixed>>}>  $groups
 */
function makeRuleWithGroups(string $matchType, array $groups): CategoryRule
{
    $rule = new CategoryRule(['match_type' => $matchType]);
    $rule->setRelation('conditionGroups', new Collection(array_map(function (array $group): CategoryRuleConditionGroup {
        $groupModel = new CategoryRuleConditionGroup(['match_type' => $group['match_type']]);
        $groupModel->setRelation('conditions', new Collection(array_map(
            fn (array $attributes): CategoryRuleCondition => new CategoryRuleCondition($attributes),
            $group['conditions'],
        )));

        return $groupModel;
    }, $groups)));

    return $rule;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeRuleTransaction(array $overrides = []): Transaction
{
    return new Transaction(array_merge([
        'name' => 'Coffee Shop',
        'merchant_name' => 'Starbucks',
        'amount' => -5.5,
        'account_id' => 1,
    ], $overrides));
}

it('a single group behaves exactly like a flat rule', function (): void {
    $rule = makeRuleWithGroups('all', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star'],
            ['field' => 'amount', 'operator' => 'less_than', 'value' => '10'],
        ]],
    ]);

    expect($rule->matches(makeRuleTransaction()))->toBeTrue()
        ->and($rule->matches(makeRuleTransaction(['amount' => -50])))->toBeFalse();
});

it('expresses "(X and Y) or Z" via match_type "any" across two groups', function (): void {
    // Group 1 (all): merchant contains "star" AND amount < 10 — i.e. "(X and Y)".
    // Group 2 (all, single condition): amount > 1000 — i.e. "Z".
    // Rule match_type "any": either group matching is enough — i.e. "... or Z".
    $rule = makeRuleWithGroups('any', [
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star'],
            ['field' => 'amount', 'operator' => 'less_than', 'value' => '10'],
        ]],
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'amount', 'operator' => 'greater_than', 'value' => '1000'],
        ]],
    ]);

    // Matches via group 1 (X and Y).
    expect($rule->matches(makeRuleTransaction(['merchant_name' => 'Starbucks', 'amount' => -5])))->toBeTrue()
        // Matches via group 2 (Z) even though group 1's conditions don't hold.
        ->and($rule->matches(makeRuleTransaction(['merchant_name' => 'Costco', 'amount' => -2000])))->toBeTrue()
        // Matches neither group.
        ->and($rule->matches(makeRuleTransaction(['merchant_name' => 'Costco', 'amount' => -50])))->toBeFalse();
});

it('expresses "(X or Y) and Z" via match_type "all" across two groups', function (): void {
    $rule = makeRuleWithGroups('all', [
        ['match_type' => 'any', 'conditions' => [
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star'],
            ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'costco'],
        ]],
        ['match_type' => 'all', 'conditions' => [
            ['field' => 'amount', 'operator' => 'less_than', 'value' => '10'],
        ]],
    ]);

    expect($rule->matches(makeRuleTransaction(['merchant_name' => 'Starbucks', 'amount' => -5])))->toBeTrue()
        ->and($rule->matches(makeRuleTransaction(['merchant_name' => 'Costco', 'amount' => -5])))->toBeTrue()
        // Group 1 (X or Y) fails — merchant matches neither.
        ->and($rule->matches(makeRuleTransaction(['merchant_name' => 'Whole Foods', 'amount' => -5])))->toBeFalse()
        // Group 2 (Z) fails — amount too high, even though group 1 matches.
        ->and($rule->matches(makeRuleTransaction(['merchant_name' => 'Starbucks', 'amount' => -50])))->toBeFalse();
});

it('never matches when it has no groups at all, rather than being vacuously true', function (): void {
    $rule = makeRuleWithGroups('all', []);

    expect($rule->matches(makeRuleTransaction()))->toBeFalse();
});
