<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForBulkTest(?User $user = null): Account
{
    $user ??= User::factory()->create();

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

    test()->actingAs($user);

    return $account;
}

it('bulkAssignCategory syncs the given category onto every selected transaction', function (): void {
    $category = Category::create(['name' => 'Subscriptions']);
    $account = makeAccountForBulkTest();

    $txn1 = Transaction::factory()->for($account)->create(['name' => 'Netflix', 'amount' => -15.99, 'currency' => 'USD']);
    $txn2 = Transaction::factory()->for($account)->create(['name' => 'Netflix Streaming', 'amount' => -15.99, 'currency' => 'USD']);
    $untouched = Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -50, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn1->id, $txn2->id]);
    $test->call('bulkAssignCategory', $category->id);

    expect($txn1->refresh()->categories()->pluck('categories.id')->all())->toBe([$category->id]);
    expect($txn2->refresh()->categories()->pluck('categories.id')->all())->toBe([$category->id]);
    expect($untouched->refresh()->categories()->pluck('categories.id')->all())->toBe([]);
});

it('bulkAssignCategory replaces (not appends to) each transaction\'s existing categories', function (): void {
    $oldCategory = Category::create(['name' => 'Old']);
    $newCategory = Category::create(['name' => 'New']);
    $account = makeAccountForBulkTest();

    $txn = Transaction::factory()->for($account)->create(['name' => 'Netflix', 'amount' => -15.99, 'currency' => 'USD']);
    $txn->categories()->sync([$oldCategory->id]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn->id]);
    $test->call('bulkAssignCategory', $newCategory->id);

    expect($txn->refresh()->categories()->pluck('categories.id')->all())->toBe([$newCategory->id]);
});

it('bulkAssignCategory clears the selection afterwards', function (): void {
    $category = Category::create(['name' => 'Subscriptions']);
    $account = makeAccountForBulkTest();
    $txn = Transaction::factory()->for($account)->create(['name' => 'Netflix', 'amount' => -15.99, 'currency' => 'USD']);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$txn->id]);
    $test->call('bulkAssignCategory', $category->id);

    expect($test->get('selected_transactions'))->toBe([]);
});

it('bulkAssignCategory refuses to categorize a transaction belonging to another user', function (): void {
    $category = Category::create(['name' => 'Subscriptions']);
    $ownAccount = makeAccountForBulkTest();
    $ownTxn = Transaction::factory()->for($ownAccount)->create(['name' => 'Mine', 'amount' => -10, 'currency' => 'USD']);

    $otherUser = User::factory()->create();
    $otherAccount = makeAccountForBulkTest($otherUser);
    $otherTxn = Transaction::factory()->for($otherAccount)->create(['name' => 'Not Mine', 'amount' => -10, 'currency' => 'USD']);

    // Re-authenticate as the original user (makeAccountForBulkTest for otherUser switched the acting user).
    test()->actingAs($ownAccount->linked_account->user);

    $test = Livewire::test('components.transactions', ['account' => $ownAccount]);
    $test->set('selected_transactions', [$ownTxn->id, $otherTxn->id]);
    $test->call('bulkAssignCategory', $category->id);

    $test->assertForbidden();
});

it('bulkDeleteTransactions deletes only manually-added transactions among the selection', function (): void {
    $account = makeAccountForBulkTest();

    $manual = Transaction::factory()->for($account)->create([
        'name' => 'Manual Entry',
        'amount' => -10,
        'currency' => 'USD',
        'original' => ['manual' => true],
    ]);
    $synced = Transaction::factory()->for($account)->create([
        'name' => 'Synced From Plaid',
        'amount' => -20,
        'currency' => 'USD',
        'original' => ['manual' => false],
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$manual->id, $synced->id]);
    $test->call('bulkDeleteTransactions');

    expect(Transaction::find($manual->id))->toBeNull();
    expect(Transaction::find($synced->id))->not->toBeNull();
});

it('bulkDeleteTransactions detaches categories from deleted transactions', function (): void {
    $category = Category::create(['name' => 'Subscriptions']);
    $account = makeAccountForBulkTest();

    $manual = Transaction::factory()->for($account)->create([
        'name' => 'Manual Entry',
        'amount' => -10,
        'currency' => 'USD',
        'original' => ['manual' => true],
    ]);
    $manual->categories()->sync([$category->id]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$manual->id]);
    $test->call('bulkDeleteTransactions');

    expect(DB::table('category_transaction')->where('transaction_id', $manual->id)->count())->toBe(0);
});

it('bulkDeleteTransactions clears the selection afterwards', function (): void {
    $account = makeAccountForBulkTest();
    $manual = Transaction::factory()->for($account)->create([
        'name' => 'Manual Entry',
        'amount' => -10,
        'currency' => 'USD',
        'original' => ['manual' => true],
    ]);

    $test = Livewire::test('components.transactions', ['account' => $account]);
    $test->set('selected_transactions', [$manual->id]);
    $test->call('bulkDeleteTransactions');

    expect($test->get('selected_transactions'))->toBe([]);
});

it('bulkDeleteTransactions refuses to delete a transaction belonging to another user', function (): void {
    $ownAccount = makeAccountForBulkTest();
    $ownTxn = Transaction::factory()->for($ownAccount)->create([
        'name' => 'Mine', 'amount' => -10, 'currency' => 'USD', 'original' => ['manual' => true],
    ]);

    $otherUser = User::factory()->create();
    $otherAccount = makeAccountForBulkTest($otherUser);
    $otherTxn = Transaction::factory()->for($otherAccount)->create([
        'name' => 'Not Mine', 'amount' => -10, 'currency' => 'USD', 'original' => ['manual' => true],
    ]);

    test()->actingAs($ownAccount->linked_account->user);

    $test = Livewire::test('components.transactions', ['account' => $ownAccount]);
    $test->set('selected_transactions', [$ownTxn->id, $otherTxn->id]);
    $test->call('bulkDeleteTransactions');

    $test->assertForbidden();
});
