<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

/**
 * Regression guard: both the transaction list's category chip and the Income/Expense report's
 * category badge used to render with `text-nowrap` and no width cap, so a long category name
 * pushed past the table/card boundary instead of truncating (found while auditing the app for
 * long-real-world-text overflow bugs).
 */
it('caps and truncates a long category name in the transaction list chip', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
    $category = Category::create(['name' => 'An Extremely Long Category Name That Should Not Blow Out The Layout']);
    $txn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -10, 'currency' => 'USD']);
    $txn->categories()->sync([$category->id]);

    test()->actingAs($user);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml('max-w-40 truncate');
});

it('caps and truncates a long category name in the Income/Expense report table', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking', 'tracking_mode' => 'tracked',
    ]);
    $category = Category::create(['name' => 'An Extremely Long Category Name That Should Not Blow Out The Layout']);
    $txn = Transaction::factory()->for($account)->create([
        'name' => 'Store', 'amount' => -10, 'currency' => 'USD', 'type' => 'expense',
    ]);
    $txn->categories()->sync([$category->id]);

    test()->actingAs($user);

    $response = test()->get('/reports/income-expense');

    $response->assertSeeHtml('max-w-32 truncate');
});
