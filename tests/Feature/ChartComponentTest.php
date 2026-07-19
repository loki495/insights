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
