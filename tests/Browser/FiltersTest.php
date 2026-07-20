<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

/**
 * @return array{0: Account, 1: Account} two accounts for the same user.
 */
function makeAccountsForFiltersBrowserTest(): array
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    $checking = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
        'tracking_mode' => 'tracked',
    ]);
    $savings = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '1111', 'name' => 'Savings',
        'official_name' => 'Savings Official', 'type' => 'depository', 'subtype' => 'savings',
        'tracking_mode' => 'tracked',
    ]);

    test()->actingAs($user);

    return [$checking, $savings];
}

it('opens the filters panel and live-filters the list via the debounced search box', function (): void {
    [$account] = makeAccountsForFiltersBrowserTest();
    Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -40, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Netflix', 'amount' => -15, 'currency' => 'USD']);

    visit('/reports/category')
        ->assertSee('Trader Joes')
        ->assertSee('Netflix')
        ->click('Filters')
        ->fill('[placeholder="Search"]', 'Trader')
        // wire:model.live.debounce needs both the browser's own debounce timer to elapse and a
        // yield for the resulting Livewire request to actually get serviced — see
        // TypeEditorTest.php's pairing test for why a single wait() covers both.
        ->wait(0.5)
        ->assertSee('Trader Joes')
        ->assertDontSee('Netflix')
        ->assertNoSmoke();
});

it('filters the list by type via the type checkboxes', function (): void {
    [$account] = makeAccountsForFiltersBrowserTest();
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 1000, 'currency' => 'USD', 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD', 'type' => 'expense']);

    // Flux's checkbox is a <ui-checkbox> custom element, not a <button> — text-based/button
    // selectors either can't find it or (worse) collide with a real button elsewhere on the page
    // that happens to share the label text (here, the "Income" type pill on the Paycheck row).
    // The `value` attribute Flux passes straight through is a reliable, explicit target instead.
    visit('/reports/category')
        ->click('Filters')
        ->click('ui-checkbox[value="income"]')
        ->wait(0.2)
        ->assertSee('Paycheck')
        ->assertDontSee('Groceries')
        ->assertNoSmoke();
});

it('selects multiple accounts via the accounts dropdown, then clears the selection', function (): void {
    [$checking, $savings] = makeAccountsForFiltersBrowserTest();
    Transaction::factory()->for($checking)->create(['name' => 'Checking Txn', 'amount' => -10, 'currency' => 'USD']);
    Transaction::factory()->for($savings)->create(['name' => 'Savings Txn', 'amount' => -20, 'currency' => 'USD']);

    $page = visit('/reports/category')
        ->click('Filters')
        ->assertSee('Checking Txn')
        ->assertSee('Savings Txn')
        ->click('-- All Accounts --')
        // Plain [value="N"] would collide with the transaction list's own bulk-select checkboxes,
        // which reuse the same account/transaction id space — scope to the dropdown's id instead.
        ->click(sprintf('#accounts-filter-dropdown ui-checkbox[value="%d"]', $checking->id))
        ->wait(0.2)
        ->assertSee('Checking Txn')
        ->assertDontSee('Savings Txn')
        ->assertDontSee('-- All Accounts --')
        ->assertNoSmoke();

    $page->click(clickVisibleButton('Clear (All Accounts)'))
        ->wait(0.2)
        ->assertSee('Checking Txn')
        ->assertSee('Savings Txn')
        ->assertNoSmoke();
});
