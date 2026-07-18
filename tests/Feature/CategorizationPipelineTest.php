<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountWithTransaction(Category $category): Account
{
    $user = User::factory()->create();
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

    $transaction = Transaction::factory()->for($account)->create([
        'amount' => -20,
        'name' => 'Bar Tab',
        'currency' => 'USD',
    ]);
    $transaction->categories()->sync([$category->id]);

    test()->actingAs($user);

    return $account;
}

it('descendants() returns a flat array of ids including self and all nested children', function (): void {
    $expenses = Category::create(['name' => 'Expenses']);
    $bars = Category::create(['name' => 'Bars', 'parent_id' => $expenses->id]);
    $leaf = Category::create(['name' => 'Bars - Andres', 'parent_id' => $bars->id]);

    expect($expenses->descendants)->toEqualCanonicalizing([$expenses->id, $bars->id, $leaf->id]);
    expect($bars->descendants)->toEqualCanonicalizing([$bars->id, $leaf->id]);
    expect($leaf->descendants)->toBe([$leaf->id]);
});

it('filters transactions by a parent category to include all its descendants', function (): void {
    $expenses = Category::create(['name' => 'Expenses']);
    $bars = Category::create(['name' => 'Bars', 'parent_id' => $expenses->id]);
    $leaf = Category::create(['name' => 'Bars - Andres', 'parent_id' => $bars->id]);

    $account = makeAccountWithTransaction($leaf);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('category_id', $expenses->id);

    expect($test->instance()->getTransactionsQuery()->count())->toBe(1);
});

it('drills one level deeper into the chart on each click, matching the categorized transaction', function (): void {
    $expenses = Category::create(['name' => 'Expenses']);
    $bars = Category::create(['name' => 'Bars', 'parent_id' => $expenses->id]);
    $leaf = Category::create(['name' => 'Bars - Andres', 'parent_id' => $bars->id]);

    $account = makeAccountWithTransaction($leaf);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    // Root view: shows the top-level ancestor of the categorized transaction.
    expect($test->get('chart_labels'))->toBe(['Expenses']);

    // Click into "Expenses": should show its child "Bars", not go empty.
    $test->call('handleChartClick', $expenses->id);
    expect($test->get('category_id'))->toBe($expenses->id);
    expect($test->get('chart_labels'))->toBe(['Bars']);
    expect($test->instance()->getTransactionsQuery()->count())->toBe(1);

    // Click into "Bars": should show the leaf "Bars - Andres".
    $test->call('handleChartClick', $bars->id);
    expect($test->get('category_id'))->toBe($bars->id);
    expect($test->get('chart_labels'))->toBe(['Bars - Andres']);
    expect($test->instance()->getTransactionsQuery()->count())->toBe(1);
});

it('goBack steps back up one level at a time', function (): void {
    $expenses = Category::create(['name' => 'Expenses']);
    $bars = Category::create(['name' => 'Bars', 'parent_id' => $expenses->id]);
    $leaf = Category::create(['name' => 'Bars - Andres', 'parent_id' => $bars->id]);

    $account = makeAccountWithTransaction($leaf);

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
    $categoryA = Category::create(['name' => 'Category A']);
    $categoryB = Category::create(['name' => 'Category B']);
    $account = makeAccountWithTransaction($categoryA);
    $transaction = $account->transactions()->firstOrFail();

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->call('saveCategory', $transaction->id, $categoryB->id);

    $transaction->refresh();
    expect($transaction->categories()->pluck('categories.id')->all())->toBe([$categoryB->id]);
});

it('transactions-updated event triggers a re-render without error', function (): void {
    $category = Category::create(['name' => 'Groceries']);
    $account = makeAccountWithTransaction($category);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->dispatch('transactions-updated');

    $test->assertOk();
});

it('createCategory creates a top-level category with a default color', function (): void {
    $category = Category::create(['name' => 'Anchor']);
    $account = makeAccountWithTransaction($category);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $created = $test->instance()->createCategory('Brand New Category', null, null);

    $category = Category::where('name', 'Brand New Category')->firstOrFail();
    expect($category->parent_id)->toBe(0);
    expect($category->color)->toBe('#3b82f6');
    expect($created)->toBe([
        'id' => $category->id,
        'name' => $category->name,
        'full_name' => $category->fullName,
        'parent_id' => 0,
        'color' => $category->color,
    ]);
});

it('createCategory nests under the given parent with the given color', function (): void {
    $parent = Category::create(['name' => 'Expenses']);
    $account = makeAccountWithTransaction($parent);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->instance()->createCategory('Subcategory', $parent->id, '#ef4444');

    $category = Category::where('name', 'Subcategory')->firstOrFail();
    expect($category->parent_id)->toBe($parent->id);
    expect($category->color)->toBe('#ef4444');
    expect($category->parent->name)->toBe('Expenses');
});

it('createCategory rejects a blank name', function (): void {
    $category = Category::create(['name' => 'Anchor']);
    $account = makeAccountWithTransaction($category);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect(fn () => $test->instance()->createCategory('   ', null, null))
        ->toThrow(InvalidArgumentException::class);
});
