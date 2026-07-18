<?php

declare(strict_types=1);

use App\Models\OriginalCategory;
use App\Services\Plaid\PlaidService;

it('returns null when the category path is missing', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_SANDBOX]);

    $result = $plaid->resolveCategory([
        'category_id' => '13005000',
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT'],
    ]);

    expect($result)->toBeNull();
});

it('returns null when the category path is empty', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_SANDBOX]);

    $result = $plaid->resolveCategory([
        'category' => [],
        'category_id' => '13005000',
    ]);

    expect($result)->toBeNull();
});

it('returns null when the category_id is missing', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_SANDBOX]);

    $result = $plaid->resolveCategory([
        'category' => ['Food and Drink', 'Restaurants'],
    ]);

    expect($result)->toBeNull();
});

it('resolves and persists a category when given valid Plaid data', function (): void {
    $plaid = app(PlaidService::class, ['environment' => PlaidService::ENV_SANDBOX]);

    $result = $plaid->resolveCategory([
        'category' => ['Food and Drink', 'Restaurants'],
        'category_id' => '13005000',
        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT', 'confidence_level' => 'HIGH'],
    ]);

    expect($result)->toBeInstanceOf(OriginalCategory::class);
    expect($result->full_path)->toBe('Food and Drink > Restaurants');
    expect($result->plaid_id)->toBe('13005000');
});
