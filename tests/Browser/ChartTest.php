<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeAccountForChartTest(): Account
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

it('renders the transaction search page with no console errors when there are no transactions yet', function (): void {
    $account = makeAccountForChartTest();

    test()->actingAs($account->linked_account->user);

    visit('/reports/category')
        ->assertSee('Transaction Search')
        ->assertNoSmoke();
});

it('renders the chart with no console errors once transactions exist', function (): void {
    $account = makeAccountForChartTest();
    $category = Category::create(['name' => 'Groceries']);
    $transaction = Transaction::factory()->for($account)->create([
        'name' => 'Whole Foods',
        'amount' => -42.50,
        'currency' => 'USD',
    ]);
    $transaction->categories()->sync([$category->id]);

    test()->actingAs($account->linked_account->user);

    visit('/reports/category')
        ->assertSee('Whole Foods')
        ->assertNoSmoke();
});

it('renders the chart with no console errors across a live 0-to-1 transaction transition', function (): void {
    $account = makeAccountForChartTest();
    Transaction::factory()->for($account)->create([
        'name' => 'Live Transition Coffee',
        'amount' => -5,
        'currency' => 'USD',
    ]);

    test()->actingAs($account->linked_account->user);

    // A search term matching nothing starts the list (and chart) at zero results, same as a
    // brand new user with no transactions yet — then clearing it re-renders the component and,
    // since chartNeedsRefresh gets set on every `updated()` hook, re-triggers the chart's
    // $wire.$watch("chart_values", ...) handler on a live 0->N transition — the exact path that
    // previously had bugs (canvas re-init, $attributes not merging).
    $page = visit('/reports/category')
        ->click('Filters')
        ->fill('search', 'no-such-transaction-xyz')
        ->wait(1)
        ->assertSee('No transactions found');

    $page->fill('search', '')
        ->wait(1)
        ->assertSee('Live Transition Coffee')
        ->assertNoSmoke();
});
