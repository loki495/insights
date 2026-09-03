<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForCategoryRuleBuilderTest(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'tracking_mode' => 'tracked',
    ]);
}

it('creates a rule through the real form and lists it afterward', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForCategoryRuleBuilderTest($user);
    $category = categoryFor($user, 'Coffee');
    Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    test()->actingAs($user);

    visit('/category-rules/create')
        ->fill('name', 'Coffee shops')
        ->select('category_id', (string) $category->id)
        ->select('#group-0-condition-0-field', 'merchant_name')
        ->fill('#group-0-condition-0-value', 'starbucks')
        ->wait(0.6)
        ->assertSee('1 matching uncategorized transaction')
        ->click(clickVisibleButton('Save'))
        ->wait(0.3)
        ->assertSee('Coffee shops')
        ->assertNoSmoke();
});

it('adds a second group and shows the connector between them', function (): void {
    $user = User::factory()->create();
    makeAccountForCategoryRuleBuilderTest($user);
    categoryFor($user, 'Coffee');

    test()->actingAs($user);

    visit('/category-rules/create')
        // No AND/OR suffix yet with only one group — there's nothing to combine, and the
        // "Groups combine via" selector that controls it isn't shown until a second group exists.
        ->assertDontSee('+ Add group (')
        ->click(clickVisibleButton('+ Add group'))
        ->assertSee('Groups combine via')
        ->assertSee('AND')
        ->click(clickVisibleButton('+ Add group (AND)'))
        ->assertNoSmoke();
});

it('applies the rule to existing matching transactions from the edit page, after confirming', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForCategoryRuleBuilderTest($user);
    $category = categoryFor($user, 'Coffee');
    $matching = Transaction::factory()->for($account)->create(['name' => 'Coffee', 'merchant_name' => 'Starbucks', 'amount' => -5, 'currency' => 'USD']);

    test()->actingAs($user);

    // wire:confirm shows a native browser confirm() dialog before dispatching the click's
    // action — see BulkActionsTest.php for why this stub is needed instead of a Pest dialog API.
    $page = visit('/category-rules/create');
    $page->script('window.confirm = () => true;');

    $page->select('category_id', (string) $category->id)
        ->select('#group-0-condition-0-field', 'merchant_name')
        ->fill('#group-0-condition-0-value', 'starbucks')
        ->wait(0.6)
        ->assertSee('Starbucks')
        ->click(clickVisibleButton('Apply to 1 existing transaction'))
        ->wait(0.3)
        ->assertSee('Applied to 1 existing transaction.')
        ->assertNoSmoke();

    expect($matching->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id]);
});
