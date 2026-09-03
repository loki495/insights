<?php

declare(strict_types=1);

use App\Models\CategoryRuleCondition;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCondition(array $overrides = []): CategoryRuleCondition
{
    return new CategoryRuleCondition(array_merge([
        'field' => 'merchant_name',
        'operator' => 'contains',
        'value' => null,
        'value_end' => null,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeConditionTransaction(array $overrides = []): Transaction
{
    return new Transaction(array_merge([
        'name' => 'Coffee Shop',
        'merchant_name' => 'Starbucks',
        'amount' => -5.5,
        'account_id' => 1,
        'created_at' => Carbon::parse('2026-06-15'),
    ], $overrides));
}

// -- name/merchant_name --

it('matches a text field via contains, case-insensitively', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'STAR']);

    expect($condition->matches(makeConditionTransaction()))->toBeTrue();
});

it('does not match a text field when the substring is absent', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'costco']);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

it('matches a text field via equals only on an exact (case-insensitive) match', function (): void {
    $exact = makeCondition(['field' => 'merchant_name', 'operator' => 'equals', 'value' => 'starbucks']);
    $partial = makeCondition(['field' => 'merchant_name', 'operator' => 'equals', 'value' => 'star']);

    expect($exact->matches(makeConditionTransaction()))->toBeTrue()
        ->and($partial->matches(makeConditionTransaction()))->toBeFalse();
});

it('matches a text field via starts_with', function (): void {
    $condition = makeCondition(['field' => 'name', 'operator' => 'starts_with', 'value' => 'coffee']);

    expect($condition->matches(makeConditionTransaction()))->toBeTrue();
});

it('matches a text field via a valid regex', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'regex', 'value' => '/^Star.+ks$/']);

    expect($condition->matches(makeConditionTransaction()))->toBeTrue();
});

it('never matches (not crashes) on a malformed regex', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'regex', 'value' => '/[unterminated']);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

it('does not match a text field when the transaction value is null', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'contains', 'value' => 'star']);

    expect($condition->matches(makeConditionTransaction(['merchant_name' => null])))->toBeFalse();
});

it('does not match a text field when the condition has no value configured', function (): void {
    $condition = makeCondition(['field' => 'merchant_name', 'operator' => 'contains', 'value' => null]);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

// -- amount --

it('matches amount by magnitude regardless of sign', function (): void {
    $condition = makeCondition(['field' => 'amount', 'operator' => 'equals', 'value' => '5.5']);

    expect($condition->matches(makeConditionTransaction(['amount' => -5.5])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['amount' => 5.5])))->toBeTrue();
});

it('matches amount via greater_than and less_than', function (): void {
    $greaterThan = makeCondition(['field' => 'amount', 'operator' => 'greater_than', 'value' => '5']);
    $lessThan = makeCondition(['field' => 'amount', 'operator' => 'less_than', 'value' => '5']);

    expect($greaterThan->matches(makeConditionTransaction(['amount' => -5.5])))->toBeTrue()
        ->and($lessThan->matches(makeConditionTransaction(['amount' => -5.5])))->toBeFalse();
});

it('matches amount via between, inclusive of both ends', function (): void {
    $condition = makeCondition(['field' => 'amount', 'operator' => 'between', 'value' => '5', 'value_end' => '6']);

    expect($condition->matches(makeConditionTransaction(['amount' => -5.5])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['amount' => -5])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['amount' => -6])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['amount' => -6.01])))->toBeFalse();
});

it('does not match amount when the configured value is not numeric', function (): void {
    $condition = makeCondition(['field' => 'amount', 'operator' => 'equals', 'value' => 'not-a-number']);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

// -- account_id --

it('matches account_id via is', function (): void {
    $condition = makeCondition(['field' => 'account_id', 'operator' => 'is', 'value' => '42']);

    expect($condition->matches(makeConditionTransaction(['account_id' => 42])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['account_id' => 7])))->toBeFalse();
});

it('does not match account_id when the condition has no value configured', function (): void {
    $condition = makeCondition(['field' => 'account_id', 'operator' => 'is', 'value' => null]);

    expect($condition->matches(makeConditionTransaction(['account_id' => 42])))->toBeFalse();
});

// -- date --

it('matches date via before/after', function (): void {
    $before = makeCondition(['field' => 'date', 'operator' => 'before', 'value' => '2026-07-01']);
    $after = makeCondition(['field' => 'date', 'operator' => 'after', 'value' => '2026-07-01']);

    expect($before->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-06-15')])))->toBeTrue()
        ->and($after->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-06-15')])))->toBeFalse();
});

it('matches date via between, inclusive of both ends', function (): void {
    $condition = makeCondition(['field' => 'date', 'operator' => 'between', 'value' => '2026-06-01', 'value_end' => '2026-06-30']);

    expect($condition->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-06-01')])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-06-30')])))->toBeTrue()
        ->and($condition->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-07-01')])))->toBeFalse();
});

it('does not match date when the transaction has no date or the condition has no value', function (): void {
    $condition = makeCondition(['field' => 'date', 'operator' => 'before', 'value' => null]);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

it('does not match date "between" when the configured value_end is missing', function (): void {
    $condition = makeCondition(['field' => 'date', 'operator' => 'between', 'value' => '2026-06-01', 'value_end' => null]);

    expect($condition->matches(makeConditionTransaction(['created_at' => Carbon::parse('2026-06-15')])))->toBeFalse();
});

it('does not match date when the configured value is not a parseable date', function (): void {
    $condition = makeCondition(['field' => 'date', 'operator' => 'before', 'value' => 'not-a-date']);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});

// -- unknown field --

it('does not match an unrecognized field', function (): void {
    $condition = makeCondition(['field' => 'something_unsupported', 'operator' => 'contains', 'value' => 'x']);

    expect($condition->matches(makeConditionTransaction()))->toBeFalse();
});
