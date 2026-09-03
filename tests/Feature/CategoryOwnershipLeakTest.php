<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\User;
use Livewire\Livewire;

/**
 * Regression guard: /reports/category/{category} route-model-bound the category directly with
 * no ownership check, and the transactions component separately trusted client-controlled
 * category ids (the wire:model-bound category_id property, and the chart-click event payload)
 * via raw Category::find() calls — both let any authenticated user disclose another user's
 * private category name (it's rendered as the page heading) just by knowing or guessing its id,
 * even though the picker's own dropdown was already correctly scoped to auth()->user()->categories().
 */
function makeCategoryLeakTestAccount(User $user): Account
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

it('403s a direct /reports/category/{id} visit to a category the user has not adopted', function (): void {
    $owner = User::factory()->create();
    $secret = Category::firstOrCreate(['parent_id' => 0, 'name' => 'Owners Secret Category']);
    $owner->categories()->syncWithoutDetaching([$secret->id => ['color' => '#111111']]);

    $stranger = User::factory()->create();

    $response = test()->actingAs($stranger)->get('/reports/category/'.$secret->id);

    $response->assertForbidden();
});

it('lets a user visit /reports/category/{id} for their own adopted category', function (): void {
    $user = User::factory()->create();
    $category = Category::firstOrCreate(['parent_id' => 0, 'name' => 'My Own Category']);
    $user->categories()->syncWithoutDetaching([$category->id => ['color' => '#111111']]);

    $response = test()->actingAs($user)->get('/reports/category/'.$category->id);

    $response->assertOk()->assertSee('My Own Category');
});

it('does not disclose another user\'s category via a crafted category_id property update', function (): void {
    $owner = User::factory()->create();
    $secret = Category::firstOrCreate(['parent_id' => 0, 'name' => 'Owners Secret Category']);
    $owner->categories()->syncWithoutDetaching([$secret->id => ['color' => '#111111']]);

    $stranger = User::factory()->create();
    test()->actingAs($stranger);
    $account = makeCategoryLeakTestAccount($stranger);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('category_id', $secret->id);

    $test->assertDontSee('Owners Secret Category');
});

it('does not disclose another user\'s category via a crafted chart-click event', function (): void {
    $owner = User::factory()->create();
    $secret = Category::firstOrCreate(['parent_id' => 0, 'name' => 'Owners Secret Category']);
    $owner->categories()->syncWithoutDetaching([$secret->id => ['color' => '#111111']]);

    $stranger = User::factory()->create();
    test()->actingAs($stranger);
    $account = makeCategoryLeakTestAccount($stranger);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('handleChartClick', $secret->id);

    $test->assertDontSee('Owners Secret Category');
});
