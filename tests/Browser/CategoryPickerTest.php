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

    // The checkbox clicks and the "Assign Category" click are pure client-side Alpine state (no
    // wire:model on the checkboxes, no server call to open the modal) — verified this by
    // measuring: the checkbox's own DOM state and the Alpine-driven "N selected" badge both
    // update in single-digit milliseconds. The final category click is the only step that
    // actually calls the server ($wire.bulkAssignCategory()), and it needs a wait() after it —
    // not because anything is slow, but because Execution::wait() yields via Amp\delay() inside
    // an Amp fiber, cooperatively handing control back to the *same* event loop this plugin's
    // in-process fake HTTP server runs its request handling on. Without an explicit yield
    // somewhere, the click's fetch() can be sent by the browser but never actually get serviced
    // before the test's own DB assertions run — and a plain PHP usleep()-based poll doesn't help
    // at all, since usleep() is a blocking OS call invisible to Amp's cooperative scheduler
    // (confirmed empirically: even a full 1s usleep-based poll never saw the write land, while a
    // single 0.1s wait() reliably does).
    visit('/reports/category')
        ->click('Select')
        ->click(sprintf('.selected_transaction[value="%d"]', $first->id))
        ->click(sprintf('.selected_transaction[value="%d"]', $second->id))
        ->assertSee('selected')
        ->click(clickVisibleButton('Assign Category'))
        ->assertSee('transaction(s) selected')
        ->click(clickVisibleButton('Groceries'))
        ->wait(0.1)
        ->assertNoSmoke();

    expect($first->fresh()->categories->pluck('name')->all())->toBe(['Groceries']);
    expect($second->fresh()->categories->pluck('name')->all())->toBe(['Groceries']);
});
