<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

/**
 * Regression guard: the "Spending This Month" chart and "Recent Transactions" grid items were
 * missing min-w-0, so their default (content-based) min-width let the doughnut chart's canvas
 * force the single-column mobile grid track wider than the viewport, causing the whole dashboard
 * to scroll horizontally — the account-card grid below them was unaffected. min-w-0 makes a
 * flex/grid item shrink to its container's available width instead of its content's min-content
 * size; without it, this class of bug is easy to reintroduce on either widget.
 */
it('does not scroll horizontally on a mobile viewport', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'tracking_mode' => 'tracked',
        'current_balance' => 100,
    ]);
    Transaction::factory()->for($account)->create([
        'name' => 'Coffee Shop', 'amount' => -5, 'currency' => 'USD', 'type' => 'expense',
    ]);

    test()->actingAs($user);

    visit('/')
        ->resize(390, 844)
        ->assertSee('Net Cash')
        ->assertScript('document.body.scrollWidth <= window.innerWidth');
});
