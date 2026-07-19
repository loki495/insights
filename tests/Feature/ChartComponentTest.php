<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

it('forwards wire:ignore, wire:key, and extra classes onto the chart\'s root element', function (): void {
    // chart.blade.php's root element must forward $attributes — wire:ignore keeps Livewire's
    // morph from touching the canvas, and wire:key lets it reconcile the element correctly
    // across the @if gate that removes it entirely when there are zero results.
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
    $category = Category::create(['name' => 'Groceries']);
    $txn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -5, 'currency' => 'USD']);
    $txn->categories()->sync([$category->id]);

    test()->actingAs($user);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    $test->assertSeeHtml('wire:key="chart-root"');
    $test->assertSeeHtml('wire:ignore');
});

it('changes the chart\'s wire:key when drilling into a category', function (): void {
    // The wire:key changes on drill-down (chart-root -> chart-{id}), so Livewire swaps in a
    // brand new canvas node — chart.blade.php's $wire.$watch callback must detect that rather
    // than assume an existing chartObj still points at a live, connected canvas.
    $parent = Category::create(['name' => 'Expenses']);
    $child = Category::create(['name' => 'Bars', 'parent_id' => $parent->id]);

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
    $txn = Transaction::factory()->for($account)->create(['name' => 'Bar Tab', 'amount' => -20, 'currency' => 'USD']);
    $txn->categories()->sync([$child->id]);

    test()->actingAs($user);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->assertSeeHtml('wire:key="chart-root"');

    $test->call('handleChartClick', $parent->id);

    $test->assertSeeHtml('wire:key="chart-'.$parent->id.'"');
    $test->assertDontSeeHtml('wire:key="chart-root"');
});

it('includes transfer-type transactions in the chart, not just reportable ones', function (): void {
    // The account view / transaction search chart is deliberately not scoped to reportable() —
    // it shows everything matching the current filters (that's what categories are for); only the
    // dedicated Reports pages exclude transfers from their aggregate totals.
    $category = Category::create(['name' => 'Credit Card Payment']);

    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Credit Card',
        'official_name' => 'Credit Card Official',
        'type' => 'credit',
        'subtype' => 'credit card',
    ]);
    $txn = Transaction::factory()->for($account)->create(['name' => 'Payment', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer']);
    $txn->categories()->sync([$category->id]);

    test()->actingAs($user);

    $test = Livewire::test('components.transactions', ['account' => $account]);

    expect($test->get('chart_labels'))->toBe(['Credit Card Payment']);
    expect($test->get('chart_values'))->toBe([200.0]);
});
