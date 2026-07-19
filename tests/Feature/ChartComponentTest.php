<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

it('forwards wire:ignore, wire:key, and extra classes onto the chart\'s root element', function (): void {
    // Regression test: chart.blade.php never referenced $attributes, so
    // wire:ignore/wire:key/class passed by the transactions component were
    // silently dropped. This let Livewire's morph touch the wire:ignore'd
    // canvas on every request, and (separately) meant wire:key couldn't
    // help Livewire reconcile the element across the @if gate that removes
    // it entirely when there are zero results.
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
    // Regression test: the chart's wire:key is "chart-{category_id}", so it
    // genuinely changes on drill-down (chart-root -> chart-{id}), meaning
    // Livewire swaps in a brand new canvas DOM node rather than reusing the
    // old one. chart.blade.php's $wire.$watch callback previously assumed
    // an existing chartObj meant the canvas was still the same live node,
    // and called update()/resize() on a Chart.js instance bound to the
    // old, now-detached canvas — leaving the new one permanently blank.
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
