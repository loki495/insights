<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\Transaction;
use App\Models\User;

it('builds path_array from root to leaf', function (): void {
    $root = OriginalCategory::create(['name' => 'Food and Drink']);
    $child = OriginalCategory::create(['name' => 'Restaurants', 'parent_id' => $root->id]);
    $leaf = OriginalCategory::create(['name' => 'Fast Food', 'parent_id' => $child->id]);

    expect($leaf->path_array)->toBe(['Food and Drink', 'Restaurants', 'Fast Food']);
    expect($root->path_array)->toBe(['Food and Drink']);
});

it('builds full_path as a > separated string', function (): void {
    $root = OriginalCategory::create(['name' => 'Food and Drink']);
    $leaf = OriginalCategory::create(['name' => 'Restaurants', 'parent_id' => $root->id]);

    expect($leaf->full_path)->toBe('Food and Drink > Restaurants');
    expect($root->full_path)->toBe('Food and Drink');
});

it('resolves parent and children relationships', function (): void {
    $root = OriginalCategory::create(['name' => 'Food and Drink']);
    $child = OriginalCategory::create(['name' => 'Restaurants', 'parent_id' => $root->id]);

    expect($child->parent->is($root))->toBeTrue();
    expect($root->children)->toHaveCount(1);
    expect($root->children->first()->is($child))->toBeTrue();
});

it('sums transaction amounts via the total accessor', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_123',
        'access_token' => 'access_123',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_acc_123',
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);
    $category = OriginalCategory::create(['name' => 'Groceries']);

    Transaction::factory()->for($account)->create([
        'amount' => -10,
        'name' => 'Store A',
        'currency' => 'USD',
        'original_category_id' => $category->id,
    ]);
    Transaction::factory()->for($account)->create([
        'amount' => -25,
        'name' => 'Store B',
        'currency' => 'USD',
        'original_category_id' => $category->id,
    ]);

    expect($category->total)->toBe(-35);
});
