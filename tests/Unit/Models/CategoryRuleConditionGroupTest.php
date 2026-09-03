<?php

declare(strict_types=1);

use App\Models\CategoryRuleCondition;
use App\Models\CategoryRuleConditionGroup;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * @param  array<int, array<string, mixed>>  $conditions
 */
function makeGroupWithConditions(string $matchType, array $conditions): CategoryRuleConditionGroup
{
    $group = new CategoryRuleConditionGroup(['match_type' => $matchType]);
    $group->setRelation('conditions', new Collection(array_map(
        fn (array $attributes): CategoryRuleCondition => new CategoryRuleCondition($attributes),
        $conditions,
    )));

    return $group;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeGroupTransaction(array $overrides = []): Transaction
{
    return new Transaction(array_merge([
        'name' => 'Coffee Shop',
        'merchant_name' => 'Starbucks',
        'amount' => -5.5,
        'account_id' => 1,
    ], $overrides));
}

it('match_type "all" requires every condition in the group to match', function (): void {
    $group = makeGroupWithConditions('all', [
        ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star'],
        ['field' => 'amount', 'operator' => 'less_than', 'value' => '10'],
    ]);

    expect($group->matches(makeGroupTransaction()))->toBeTrue()
        ->and($group->matches(makeGroupTransaction(['amount' => -50])))->toBeFalse();
});

it('match_type "any" requires only one condition in the group to match', function (): void {
    $group = makeGroupWithConditions('any', [
        ['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'costco'],
        ['field' => 'amount', 'operator' => 'less_than', 'value' => '10'],
    ]);

    expect($group->matches(makeGroupTransaction()))->toBeTrue()
        ->and($group->matches(makeGroupTransaction(['amount' => -50, 'merchant_name' => 'Costco'])))->toBeTrue()
        ->and($group->matches(makeGroupTransaction(['amount' => -50])))->toBeFalse();
});

it('never matches when it has no conditions at all, rather than being vacuously true', function (): void {
    $group = makeGroupWithConditions('all', []);

    expect($group->matches(makeGroupTransaction()))->toBeFalse();
});
