<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

/**
 * The type-editor modal's own type/pairing buttons ("Transfer", "Unpair", ...) reuse text that
 * also appears on real, visible transaction-list rows elsewhere on the same page (e.g. another
 * transaction whose actual type is "Transfer") — so a page-wide `button:visible:has-text(...)`
 * is still ambiguous here, unlike the hidden-duplicate case `clickVisibleButton()` handles.
 * Scoping to the modal's id (added specifically for this) disambiguates reliably.
 */
function clickInTypeEditor(string $text): string
{
    return sprintf('#type-editor-modal button:visible:has-text(%s)', json_encode($text));
}

/**
 * @return array{0: Account, 1: Account} two accounts (same user) — transfer pairing requires the
 *                                       two legs to live on different accounts.
 */
function makeAccountsForTypeEditorBrowserTest(): array
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
    $card = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(), 'mask' => '1111', 'name' => 'Card',
        'official_name' => 'Card Official', 'type' => 'credit', 'subtype' => 'credit card',
        'tracking_mode' => 'tracked',
    ]);

    test()->actingAs($user);

    return [$checking, $card];
}

it('opens the type editor and sets a type on an unclassified transaction', function (): void {
    [$account] = makeAccountsForTypeEditorBrowserTest();
    $transaction = Transaction::factory()->for($account)->create(['name' => 'Freelance Payment', 'amount' => 800, 'currency' => 'USD', 'type' => null]);

    visit('/reports/category')
        ->click('Unclassified')
        ->assertSee('Type')
        ->click(clickVisibleButton('Income'))
        ->wait(0.1)
        ->assertSee('Income')
        ->assertNoSmoke();

    expect($transaction->fresh()->type)->toBe('income');
});

it('sets a transaction to transfer and pairs it with a candidate from another account', function (): void {
    [$checking, $card] = makeAccountsForTypeEditorBrowserTest();
    $transaction = Transaction::factory()->for($checking)->create(['name' => 'Card Payment', 'amount' => -250, 'currency' => 'USD', 'type' => 'expense']);
    $other = Transaction::factory()->for($card)->create(['name' => 'Payment Received Xyz', 'amount' => 250, 'currency' => 'USD', 'type' => 'transfer']);

    visit('/reports/category')
        ->click(clickVisibleButton('Expense'))
        ->click(clickInTypeEditor('Transfer'))
        ->assertSee('Transfer Pair')
        ->fill('[placeholder="Search for the other leg by name/merchant..."]', 'Xyz')
        // The debounce (400ms) is a real browser setTimeout, unrelated to the PHP-side Amp event
        // loop; the wait() afterwards both clears that timer and yields long enough for the
        // resulting $wire.searchTransferPairCandidates() round trip to actually get serviced (see
        // the bulk-assign test in CategoryPickerTest.php for why a plain sleep can't do this).
        ->wait(0.5)
        ->click(clickInTypeEditor('Payment Received Xyz'))
        ->wait(0.1)
        ->assertSee('Payment Received Xyz')
        ->assertNoSmoke();

    expect($transaction->fresh()->transfer_pair_id)->toBe($other->id);
    expect($other->fresh()->transfer_pair_id)->toBe($transaction->id);
});

it('unpairs an existing transfer pair from the editor', function (): void {
    [$checking, $card] = makeAccountsForTypeEditorBrowserTest();
    $transaction = Transaction::factory()->for($checking)->create(['name' => 'Card Payment', 'amount' => -250, 'currency' => 'USD', 'type' => 'transfer']);
    $other = Transaction::factory()->for($card)->create(['name' => 'Payment Received', 'amount' => 250, 'currency' => 'USD', 'type' => 'transfer']);
    $transaction->pairWith($other);

    visit('/reports/category')
        ->click(sprintf('button[data-transaction-id="%d"]:visible', $transaction->id))
        ->assertSee('Payment Received')
        ->click(clickInTypeEditor('Unpair'))
        ->wait(0.1)
        ->assertNoSmoke();

    expect($transaction->fresh()->transfer_pair_id)->toBeNull();
    expect($other->fresh()->transfer_pair_id)->toBeNull();
});

it('bulk-assigns a type to multiple selected transactions', function (): void {
    [$account] = makeAccountsForTypeEditorBrowserTest();
    $first = Transaction::factory()->for($account)->create(['name' => 'Trader Joes', 'amount' => -40, 'currency' => 'USD', 'type' => 'expense']);
    $second = Transaction::factory()->for($account)->create(['name' => 'Safeway', 'amount' => -35, 'currency' => 'USD', 'type' => 'expense']);

    visit('/reports/category')
        ->click('Select')
        ->click(sprintf('.selected_transaction[value="%d"]', $first->id))
        ->click(sprintf('.selected_transaction[value="%d"]', $second->id))
        ->assertSee('selected')
        ->click('Assign Type')
        ->click(clickVisibleButton('Adjustment'))
        // See CategoryPickerTest.php's bulk-assign test for why this needs a wait() rather than
        // any amount of plain PHP sleeping — same underlying Amp event-loop yield requirement.
        ->wait(0.1)
        ->assertNoSmoke();

    expect($first->fresh()->type)->toBe('adjustment');
    expect($second->fresh()->type)->toBe('adjustment');
});
