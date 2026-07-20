<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForBulkActionsBrowserTest(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '0000', 'name' => 'Checking',
        'official_name' => 'Checking Official', 'type' => 'depository', 'subtype' => 'checking',
        'tracking_mode' => 'tracked',
    ]);

    test()->actingAs($user);

    return $account;
}

it('selects and deselects every transaction via Select All / Deselect All', function (): void {
    $account = makeAccountForBulkActionsBrowserTest();
    Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -40, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Safeway', 'amount' => -35, 'currency' => 'USD']);
    Transaction::factory()->for($account)->create(['name' => 'Netflix', 'amount' => -15, 'currency' => 'USD']);

    visit('/reports/category')
        ->click('Select')
        ->click('Select All')
        ->assertSee('3')
        ->assertSee('selected')
        ->click('Deselect All')
        ->assertDontSee('selected')
        ->assertNoSmoke();
});

it('clears the selection via the Clear selection link without leaving select mode', function (): void {
    $account = makeAccountForBulkActionsBrowserTest();
    $first = Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -40, 'currency' => 'USD']);
    $second = Transaction::factory()->for($account)->create(['name' => 'Safeway', 'amount' => -35, 'currency' => 'USD']);

    visit('/reports/category')
        ->click('Select')
        ->click(sprintf('.selected_transaction[value="%d"]', $first->id))
        ->click(sprintf('.selected_transaction[value="%d"]', $second->id))
        ->assertSee('selected')
        ->click('Clear selection')
        ->assertDontSee('selected')
        // Still in select mode — the checkboxes and "Cancel Select" should remain, only the
        // selection itself was cleared.
        ->assertSee('Cancel Select')
        ->assertNoSmoke();
});

it('bulk-deletes only the manually-added transactions among the selection, after confirming', function (): void {
    $account = makeAccountForBulkActionsBrowserTest();
    $manual = Transaction::factory()->for($account)->create(['name' => 'Manual Entry', 'amount' => -10, 'currency' => 'USD', 'original' => ['manual' => true]]);
    $synced = Transaction::factory()->for($account)->create(['name' => 'Synced From Plaid', 'amount' => -20, 'currency' => 'USD']);

    // wire:confirm shows a native browser confirm() dialog before dispatching the click's action.
    // Pest's browser plugin has no dialog-handling API of its own, and Playwright auto-dismisses
    // unhandled dialogs (equivalent to clicking Cancel) — so without this, the delete would never
    // actually fire. Stubbing window.confirm() to auto-accept mirrors a real user clicking "OK".
    $page = visit('/reports/category');
    $page->script('window.confirm = () => true;');

    $page->click('Select')
        ->click('Select All')
        ->click('Delete Selected')
        ->wait(0.2)
        ->assertDontSee('Manual Entry')
        ->assertSee('Synced From Plaid')
        ->assertNoSmoke();

    expect(Transaction::find($manual->id))->toBeNull();
    expect(Transaction::find($synced->id))->not->toBeNull();
});
