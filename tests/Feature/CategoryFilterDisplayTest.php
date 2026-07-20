<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForCategoryFilterDisplayTest(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
    ]);

    test()->actingAs($user);

    return $account;
}

it('updates the displayed category name when the Category filter changes', function (): void {
    makeAccountForCategoryFilterDisplayTest();
    $category = Category::create(['name' => 'Groceries']);

    $test = Livewire::test('components.transactions')
        ->set('category_id', $category->id);

    expect($test->instance()->category?->id)->toBe($category->id);
    $test->assertSee('Groceries');
});

it('does not corrupt original_category when the Category filter changes (regression: copy/paste bug)', function (): void {
    makeAccountForCategoryFilterDisplayTest();
    $category = Category::create(['name' => 'Groceries']);
    // An OriginalCategory that happens to share the same id as $category — the bug looked this
    // up via the wrong model class using the Category's id, so this id collision is exactly what
    // would have made the bug produce a plausible-looking (but wrong) result instead of just null.
    OriginalCategory::create(['id' => $category->id, 'name' => 'Unrelated Plaid Category', 'plaid_id' => 'x']);

    $test = Livewire::test('components.transactions')
        ->set('category_id', $category->id);

    expect($test->instance()->original_category)->toBeNull();
});
