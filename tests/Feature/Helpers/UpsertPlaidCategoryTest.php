<?php

declare(strict_types=1);

use App\Models\OriginalCategory;

it('creates a full category hierarchy from a path array', function (): void {
    $leaf = upsertPlaidCategory(
        ['Food and Drink', 'Restaurants', 'Fast Food'],
        '13005032',
        ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_FAST_FOOD', 'confidence_level' => 'VERY_HIGH'],
    );

    expect(OriginalCategory::count())->toBe(3);
    expect($leaf->name)->toBe('Fast Food');
    expect($leaf->full_path)->toBe('Food and Drink > Restaurants > Fast Food');
});

it('only sets plaid/personal-finance data on the leaf node', function (): void {
    $leaf = upsertPlaidCategory(
        ['Food and Drink', 'Restaurants'],
        '13005000',
        ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT', 'confidence_level' => 'HIGH'],
    );

    $root = OriginalCategory::where('name', 'Food and Drink')->firstOrFail();

    expect($root->plaid_id)->toBeNull();
    expect($root->pf_primary)->toBeNull();

    expect($leaf->plaid_id)->toBe('13005000');
    expect($leaf->pf_primary)->toBe('FOOD_AND_DRINK');
    expect($leaf->pf_detailed)->toBe('FOOD_AND_DRINK_RESTAURANT');
    expect($leaf->pf_confidence)->toBe('HIGH');
});

it('reuses existing categories instead of duplicating on repeated calls', function (): void {
    upsertPlaidCategory(['Food and Drink', 'Restaurants'], '13005000', ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT']);
    upsertPlaidCategory(['Food and Drink', 'Restaurants'], '13005000', ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT']);

    expect(OriginalCategory::count())->toBe(2);
});

it('does not overwrite an already-populated leaf on a later call', function (): void {
    $leaf = upsertPlaidCategory(['Food and Drink', 'Restaurants'], '13005000', ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT']);

    // Simulate a later Plaid payload for the same category with different (e.g. stale) data
    upsertPlaidCategory(['Food and Drink', 'Restaurants'], '13005000', ['primary' => 'SOMETHING_ELSE', 'detailed' => 'SOMETHING_ELSE_DETAILED']);

    expect($leaf->fresh()->pf_primary)->toBe('FOOD_AND_DRINK');
});

it('skips non-string or empty path segments', function (): void {
    $leaf = upsertPlaidCategory(
        ['Food and Drink', '', 'Restaurants'],
        '13005000',
        ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT'],
    );

    expect(OriginalCategory::count())->toBe(2);
    expect($leaf->full_path)->toBe('Food and Drink > Restaurants');
});
