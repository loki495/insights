<?php

declare(strict_types=1);

use App\Actions\BuildCategoryBreakdownForFilteredTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeBreakdownTestAccount(): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
}

it('rolls a transaction categorized 3 levels deep up to its top-level ancestor when unfiltered', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;

    $expenses = Category::create(['name' => 'Expenses']);
    $food = Category::create(['name' => 'Food', 'parent_id' => $expenses->id]);
    $restaurants = Category::create(['name' => 'Restaurants', 'parent_id' => $food->id]);

    $txn = Transaction::factory()->for($account)->create(['name' => 'Dinner', 'amount' => -40, 'currency' => 'USD']);
    $txn->categories()->sync([$restaurants->id]);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), null);
    expect($result)->toMatchArray(['ids' => [$expenses->id], 'labels' => ['Expenses'], 'values' => [40.0]]);
});

it('buckets uncategorized transactions separately as "Uncategorized"', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;

    Transaction::factory()->for($account)->create(['name' => 'Mystery charge', 'amount' => -15, 'currency' => 'USD']);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), null);
    expect($result)->toMatchArray(['ids' => [0], 'labels' => ['Uncategorized'], 'values' => [15.0]]);
});

it('aggregates multiple top-level categories into separate buckets', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;

    $groceries = Category::create(['name' => 'Groceries']);
    $rent = Category::create(['name' => 'Rent']);

    $txn1 = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -30, 'currency' => 'USD']);
    $txn1->categories()->sync([$groceries->id]);
    $txn2 = Transaction::factory()->for($account)->create(['name' => 'Landlord', 'amount' => -1000, 'currency' => 'USD']);
    $txn2->categories()->sync([$rent->id]);
    $txn3 = Transaction::factory()->for($account)->create(['name' => 'More groceries', 'amount' => -20, 'currency' => 'USD']);
    $txn3->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), null);

    $byLabel = array_combine($result['labels'], $result['values']);
    expect($byLabel)->toBe(['Groceries' => 50.0, 'Rent' => 1000.0]);
});

it('buckets by immediate child (not grandchild) when drilled down into a parent category', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;

    $expenses = Category::create(['name' => 'Expenses']);
    $food = Category::create(['name' => 'Food', 'parent_id' => $expenses->id]);
    $restaurants = Category::create(['name' => 'Restaurants', 'parent_id' => $food->id]);
    $transport = Category::create(['name' => 'Transport', 'parent_id' => $expenses->id]);

    $dinnerTxn = Transaction::factory()->for($account)->create(['name' => 'Dinner', 'amount' => -40, 'currency' => 'USD']);
    $dinnerTxn->categories()->sync([$restaurants->id]);
    $trainTxn = Transaction::factory()->for($account)->create(['name' => 'Train', 'amount' => -10, 'currency' => 'USD']);
    $trainTxn->categories()->sync([$transport->id]);

    // A transaction under a completely different top-level category must not leak into the
    // drill-down view for $expenses.
    $other = Category::create(['name' => 'Other']);
    $otherTxn = Transaction::factory()->for($account)->create(['name' => 'Unrelated', 'amount' => -5, 'currency' => 'USD']);
    $otherTxn->categories()->sync([$other->id]);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), $expenses->id);

    $byLabel = array_combine($result['labels'], $result['values']);
    expect($byLabel)->toBe(['Food' => 40.0, 'Transport' => 10.0]);
});

it('buckets a transaction categorized directly as the drilled-down category under itself', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;

    $expenses = Category::create(['name' => 'Expenses']);
    Category::create(['name' => 'Food', 'parent_id' => $expenses->id]);

    $txn = Transaction::factory()->for($account)->create(['name' => 'Misc expense', 'amount' => -25, 'currency' => 'USD']);
    $txn->categories()->sync([$expenses->id]);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), $expenses->id);
    expect($result)->toMatchArray(['ids' => [$expenses->id], 'labels' => ['Expenses'], 'values' => [25.0]]);
});

it('decorates each bucket with the acting user\'s own adopted color, not another user\'s', function (): void {
    $account = makeBreakdownTestAccount();
    $user = $account->linked_account->user;
    $otherUser = User::factory()->create();

    $groceries = Category::create(['name' => 'Groceries']);
    $user->categories()->syncWithoutDetaching([$groceries->id => ['color' => '#111111']]);
    $otherUser->categories()->syncWithoutDetaching([$groceries->id => ['color' => '#999999']]);

    $txn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -10, 'currency' => 'USD']);
    $txn->categories()->sync([$groceries->id]);

    $result = BuildCategoryBreakdownForFilteredTransactionsAction::run($user, Transaction::query(), null);

    expect($result['colors'])->toBe(['#111111']);
});
