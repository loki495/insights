<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForCategoryPickerTest(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Checking',
        'official_name' => 'Checking Official',
        'type' => 'depository',
        'subtype' => 'checking',
        'tracking_mode' => 'tracked',
    ]);
}

/**
 * Several category/type names (e.g. "Income") are reused verbatim elsewhere on this page — the
 * bulk type-assign dropdown, the type-editor modal — as hidden (x-show="false") DOM nodes that
 * still exist and still match a plain text locator, just with zero rendered size. A scoped
 * `button:visible:has-text(...)` selector (Playwright's own `:visible` pseudo-class) is the
 * reliable way to target the one actually-visible match instead of hanging on an ambiguous one.
 */
function clickVisibleButton(string $text): string
{
    return sprintf('button:visible:has-text(%s)', json_encode($text));
}

it('opens the category picker and assigns a top-level category', function (): void {
    $account = makeAccountForCategoryPickerTest();
    Category::create(['name' => 'Income']);
    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Paycheck',
        'amount' => 1500,
        'currency' => 'USD',
    ]);

    test()->actingAs($account->linked_account->user);

    visit('/reports/category')
        ->click('Set category')
        ->assertSee('Add Category')
        ->click(clickVisibleButton('Income'))
        ->assertSee('Income')
        ->assertNoSmoke();

    expect($transaction->fresh()->categories->pluck('name')->all())->toBe(['Income']);
});

it('drills into a category with children and back out', function (): void {
    $account = makeAccountForCategoryPickerTest();
    $expenses = Category::create(['name' => 'Expenses']);
    Category::create(['name' => 'Groceries', 'parent_id' => $expenses->id]);
    Category::create(['name' => 'Income']);
    Transaction::factory()->for($account)->create([
        'name' => 'Whole Foods',
        'amount' => -60,
        'currency' => 'USD',
    ]);

    test()->actingAs($account->linked_account->user);

    // Drilling in: click either chevron on the "Expenses" row (both trigger the same
    // drillInto()) — nth=0 just picks the first of the two.
    $page = visit('/reports/category')
        ->click('Set category')
        ->assertSee('Expenses')
        ->assertDontSee('Groceries')
        ->click('[title="Browse subcategories"] >> nth=0')
        ->assertSee('Groceries')
        ->assertDontSee('Income');

    // Drill back up via the "< Expenses" breadcrumb button — should land back on the top level.
    $page->click(clickVisibleButton('Expenses'))
        ->assertSee('Income')
        ->assertNoSmoke();
});

it('filters categories via search', function (): void {
    $account = makeAccountForCategoryPickerTest();
    $expenses = Category::create(['name' => 'Expenses']);
    Category::create(['name' => 'Groceries', 'parent_id' => $expenses->id]);
    Category::create(['name' => 'Income']);
    Transaction::factory()->for($account)->create([
        'name' => 'Whole Foods',
        'amount' => -60,
        'currency' => 'USD',
    ]);

    test()->actingAs($account->linked_account->user);

    visit('/reports/category')
        ->click('Set category')
        ->fill('[placeholder="Search categories..."]', 'groc')
        ->assertSee('Expenses > Groceries')
        ->assertDontSee('Income')
        ->assertNoSmoke();
});

it('creates a new category inline and assigns it', function (): void {
    $account = makeAccountForCategoryPickerTest();
    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'New Coffee Shop',
        'amount' => -6,
        'currency' => 'USD',
    ]);

    test()->actingAs($account->linked_account->user);

    visit('/reports/category')
        ->click('Set category')
        // Plain text starting with "+" gets misread as an (invalid) CSS combinator by Pest's
        // explicit-selector detection rather than matched as text — button:has-text(...) sidesteps it.
        ->click('button:has-text("+ Create new category")')
        ->fill('[placeholder="e.g. Groceries"]', 'Coffee')
        ->click('Create & Assign')
        ->assertSee('Coffee')
        ->assertNoSmoke();

    expect($transaction->fresh()->categories->pluck('name')->all())->toBe(['Coffee']);
    expect(Category::where('name', 'Coffee')->exists())->toBeTrue();
});

it('bulk-assigns a category to multiple selected transactions', function (): void {
    $account = makeAccountForCategoryPickerTest();
    Category::create(['name' => 'Groceries']);
    $first = Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -40, 'currency' => 'USD']);
    $second = Transaction::factory()->for($account)->create(['name' => 'Safeway', 'amount' => -35, 'currency' => 'USD']);

    test()->actingAs($account->linked_account->user);

    // The Flux `<ui-checkbox>` custom element's own DOM state (checked, aria-checked, value)
    // updates immediately on click, but its sync into Alpine's `selected_transactions` model
    // lags slightly behind — a real user's click-then-look-at-the-badge has that gap covered for
    // free, but back-to-back scripted clicks need an explicit wait() for it to catch up.
    visit('/reports/category')
        ->click('Select')
        ->click(sprintf('.selected_transaction[value="%d"]', $first->id))
        ->wait(1)
        ->click(sprintf('.selected_transaction[value="%d"]', $second->id))
        ->wait(1)
        ->assertSee('selected')
        ->click(clickVisibleButton('Assign Category'))
        ->assertSee('transaction(s) selected')
        ->wait(1)
        ->click(clickVisibleButton('Groceries'))
        ->wait(1)
        ->assertNoSmoke();

    expect($first->fresh()->categories->pluck('name')->all())->toBe(['Groceries']);
    expect($second->fresh()->categories->pluck('name')->all())->toBe(['Groceries']);
});
