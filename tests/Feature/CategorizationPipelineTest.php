<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Account}
 */
function makeUserAndAccount(): array
{
    $user = User::factory()->create();
    test()->actingAs($user);

    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ]);

    return [$user, $account];
}

function makeTransactionTaggedWith(Account $account, Category $category): Transaction
{
    $transaction = Transaction::factory()->for($account)->create([
        'amount' => -20,
        'name' => 'Bar Tab',
        'currency' => 'USD',
    ]);
    $transaction->categories()->sync([$category->id]);

    return $transaction;
}

it('searching transactions does not throw an ambiguous column error', function (): void {
    // Regression test: Transaction::scopeReportable() referenced an
    // unqualified `parent_id`, which is genuinely ambiguous once the
    // search feature's leftJoin to original_categories (which also has
    // a parent_id column) is active. Any search term used to 500.
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $test = Livewire::test('components.transactions', ['account' => $account])
        ->set('search', 'Bar');

    $test->assertOk();
});

it('descendants() returns a flat array of ids including self and all nested children', function (): void {
    $expenses = Category::create(['name' => 'Expenses']);
    $bars = Category::create(['name' => 'Bars', 'parent_id' => $expenses->id]);
    $leaf = Category::create(['name' => 'Bars - Alex', 'parent_id' => $bars->id]);

    expect($expenses->descendants)->toEqualCanonicalizing([$expenses->id, $bars->id, $leaf->id])
        ->and($bars->descendants)->toEqualCanonicalizing([$bars->id, $leaf->id])
        ->and($leaf->descendants)->toBe([$leaf->id]);
});

it('filters transactions by a parent category to include all its descendants', function (): void {
    [$user, $account] = makeUserAndAccount();
    $expenses = categoryFor($user, 'Expenses');
    $bars = categoryFor($user, 'Bars', $expenses->id);
    $leaf = categoryFor($user, 'Bars - Alex', $bars->id);

    makeTransactionTaggedWith($account, $leaf);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('category_id', $expenses->id);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(1);
});

it('drills one level deeper into the chart on each click, matching the categorized transaction', function (): void {
    [$user, $account] = makeUserAndAccount();
    $expenses = categoryFor($user, 'Expenses');
    $bars = categoryFor($user, 'Bars', $expenses->id);
    $leaf = categoryFor($user, 'Bars - Alex', $bars->id);

    makeTransactionTaggedWith($account, $leaf);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    // Root view: shows the top-level ancestor of the categorized transaction.
    expect($test->get('chart_labels'))->toBe(['Expenses']);

    // Click into "Expenses": should show its child "Bars", not go empty.
    $test->call('handleChartClick', $expenses->id);
    expect($test->get('category_id'))->toBe($expenses->id)
        ->and($test->get('chart_labels'))->toBe(['Bars'])
        ->and($test->instance()->getTransactionsQuery()->count())->toBe(1);

    // Click into "Bars": should show the leaf "Bars - Alex".
    $test->call('handleChartClick', $bars->id);
    expect($test->get('category_id'))->toBe($bars->id)
        ->and($test->get('chart_labels'))->toBe(['Bars - Alex'])
        ->and($test->instance()->getTransactionsQuery()->count())->toBe(1);
});

it('goBack steps back up one level at a time', function (): void {
    [$user, $account] = makeUserAndAccount();
    $expenses = categoryFor($user, 'Expenses');
    $bars = categoryFor($user, 'Bars', $expenses->id);
    $leaf = categoryFor($user, 'Bars - Alex', $bars->id);

    makeTransactionTaggedWith($account, $leaf);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('handleChartClick', $expenses->id);
    $test->call('handleChartClick', $bars->id);

    expect($test->get('category_id'))->toBe($bars->id);

    $test->call('goBack');
    expect($test->get('category_id'))->toBe($expenses->id);

    $test->call('goBack');
    expect($test->get('category_id'))->toBe(0);
});

it('saveCategory replaces (not appends) a transaction\'s category', function (): void {
    [$user, $account] = makeUserAndAccount();
    $categoryA = categoryFor($user, 'Category A');
    $categoryB = categoryFor($user, 'Category B');
    $transaction = makeTransactionTaggedWith($account, $categoryA);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('saveCategory', $transaction->id, $categoryB->id);

    $transaction->refresh();
    expect($transaction->categories()->pluck('categories.id')->all())->toBe([$categoryB->id]);
});

it('shows a "Set category" button when a transaction has no category', function (): void {
    [, $account] = makeUserAndAccount();
    Transaction::factory()->for($account)->create(['name' => 'Mystery Purchase', 'amount' => -15, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml('Set category');
});

it('does not show a "Set category" button once a category is assigned', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Groceries');
    makeTransactionTaggedWith($account, $category);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertDontSeeHtml('Set category');
});

it('transactions-updated event triggers a re-render without error', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Groceries');
    makeTransactionTaggedWith($account, $category);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->dispatch('transactions-updated');

    $test->assertOk();
});

it('createCategory creates a top-level category with a default color', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $created = $test->instance()->createCategory('Brand New Category', null, null);

    $newCategory = Category::where('name', 'Brand New Category')->firstOrFail();
    $pivotColor = $user->categories()->find($newCategory->id)->pivot->color;

    expect($newCategory->parent_id)->toBe(0)
        ->and($pivotColor)->toBe('#3b82f6')
        ->and($created)->toBe([
            'id' => $newCategory->id,
            'name' => $newCategory->name,
            'full_name' => $newCategory->fullName,
            'parent_id' => 0,
            'color' => $pivotColor,
        ]);
});

it('createCategory nests under the given parent with the given color', function (): void {
    [$user, $account] = makeUserAndAccount();
    $parent = categoryFor($user, 'Expenses');
    makeTransactionTaggedWith($account, $parent);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->instance()->createCategory('Subcategory', $parent->id, '#ef4444');

    $category = Category::where('name', 'Subcategory')->firstOrFail();
    $pivotColor = $user->categories()->find($category->id)->pivot->color;

    expect($category->parent_id)->toBe($parent->id)
        ->and($pivotColor)->toBe('#ef4444')
        ->and($category->parent->name)->toBe('Expenses');
});

it('createCategory rejects a blank name', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect(fn () => $test->instance()->createCategory('   ', null, null))
        ->toThrow(InvalidArgumentException::class);
});

it('suggestCategoriesForTransaction suggests the category most used by other transactions from the same merchant', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Groceries');
    makeTransactionTaggedWith($account, $category);

    $priorTxn = Transaction::factory()->for($account)->create(['name' => 'Costco Warehouse', 'merchant_name' => 'Costco', 'amount' => -50, 'currency' => 'USD']);
    $priorTxn->categories()->sync([$category->id]);

    $newTxn = Transaction::factory()->for($account)->create(['name' => 'Costco Gas', 'merchant_name' => 'Costco', 'amount' => -30, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $suggestions = $test->instance()->suggestCategoriesForTransaction($newTxn->id);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['id'])->toBe($category->id);
});

it('suggestCategoriesForTransaction falls back to original-category correlation when the merchant differs', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $originalCategory = OriginalCategory::create(['name' => 'Fast Food']);
    $target = categoryFor($user, 'Eating Out');

    $priorTxn = Transaction::factory()->for($account)->create([
        'name' => 'Burger Place Purchase',
        'merchant_name' => 'Burger Place',
        'original_category_id' => $originalCategory->id,
        'amount' => -12,
        'currency' => 'USD',
    ]);
    $priorTxn->categories()->sync([$target->id]);

    $newTxn = Transaction::factory()->for($account)->create([
        'name' => 'Taco Stand Purchase',
        'merchant_name' => 'Taco Stand',
        'original_category_id' => $originalCategory->id,
        'amount' => -9,
        'currency' => 'USD',
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $suggestions = $test->instance()->suggestCategoriesForTransaction($newTxn->id);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['id'])->toBe($target->id);
});

it('suggestCategoriesForTransaction excludes categories already assigned to the transaction', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $priorTxn = Transaction::factory()->for($account)->create(['name' => 'Costco Warehouse', 'merchant_name' => 'Costco', 'amount' => -50, 'currency' => 'USD']);
    $priorTxn->categories()->sync([$category->id]);

    $existingTxn = Transaction::factory()->for($account)->create(['name' => 'Costco Gas', 'merchant_name' => 'Costco', 'amount' => -30, 'currency' => 'USD']);
    $existingTxn->categories()->sync([$category->id]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $suggestions = $test->instance()->suggestCategoriesForTransaction($existingTxn->id);

    expect($suggestions)->toBe([]);
});

it('suggestCategoriesForTransaction returns at most two suggestions with the merchant match first', function (): void {
    [$user, $account] = makeUserAndAccount();
    $category = categoryFor($user, 'Anchor');
    makeTransactionTaggedWith($account, $category);

    $merchantCategory = categoryFor($user, 'Merchant Match');
    $originalCategory = OriginalCategory::create(['name' => 'Some Original']);
    $originalCategoryCategory = categoryFor($user, 'Original Match');

    $merchantTxn = Transaction::factory()->for($account)->create(['name' => 'Widget Purchase', 'merchant_name' => 'Widget Co', 'amount' => -20, 'currency' => 'USD']);
    $merchantTxn->categories()->sync([$merchantCategory->id]);

    $originalTxn = Transaction::factory()->for($account)->create([
        'name' => 'Other Purchase',
        'merchant_name' => 'Different Merchant',
        'original_category_id' => $originalCategory->id,
        'amount' => -15,
        'currency' => 'USD',
    ]);
    $originalTxn->categories()->sync([$originalCategoryCategory->id]);

    $target = Transaction::factory()->for($account)->create([
        'name' => 'Widget Purchase 2',
        'merchant_name' => 'Widget Co',
        'original_category_id' => $originalCategory->id,
        'amount' => -25,
        'currency' => 'USD',
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $suggestions = $test->instance()->suggestCategoriesForTransaction($target->id);

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions[0]['id'])->toBe($merchantCategory->id)
        ->and($suggestions[1]['id'])->toBe($originalCategoryCategory->id);
});

/**
 * Regression test for a cross-tenant leak found while implementing per-user category adoption:
 * merchantSuggestions()/topCategoryFor() queried Transaction::query() with no account/user
 * scoping at all, so suggestions were computed from every user's transaction history system-wide.
 * Fixed by scoping both to the acting user's own accounts, same pattern as
 * BuildTransactionsQueryAction. Both users adopt the *same* shared category row here deliberately
 * — with a different category per user, adoption-scoping alone would already hide the leak
 * (userB's `$this->categories->firstWhere(...)` simply wouldn't find userA's category), which
 * would make this test pass even without the account-scoping fix and prove nothing. Sharing the
 * row is what actually exercises the fix: userB has legitimate access to the category itself, but
 * still must never see a suggestion driven by userA's private merchant-categorization history.
 */
it('suggestCategoriesForTransaction never surfaces another user\'s categorization of the same merchant', function (): void {
    [$userA, $accountA] = makeUserAndAccount();
    $categoryA = categoryFor($userA, 'Groceries');
    $priorTxnA = Transaction::factory()->for($accountA)->create(['name' => 'Costco Warehouse', 'merchant_name' => 'Costco', 'amount' => -50, 'currency' => 'USD']);
    $priorTxnA->categories()->sync([$categoryA->id]);

    // makeUserAndAccount() also switches the acting session to userB, matching what a real
    // second request from userB would look like. userB adopts the SAME shared "Groceries" row.
    [$userB, $accountB] = makeUserAndAccount();
    categoryFor($userB, 'Groceries');
    $newTxnB = Transaction::factory()->for($accountB)->create(['name' => 'Costco Gas', 'merchant_name' => 'Costco', 'amount' => -30, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $accountB]);
    $suggestions = $test->instance()->suggestCategoriesForTransaction($newTxnB->id);

    expect($suggestions)->toBe([]);
});
