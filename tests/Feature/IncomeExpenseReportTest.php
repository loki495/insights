<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

function makeAccountForIncomeExpenseReportTest(User $user, array $overrides = []): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Account',
        'official_name' => 'Account Official',
        'type' => 'depository',
        'subtype' => 'checking',
    ], $overrides));
}

test('guests are redirected to the login page', function (): void {
    $response = $this->get('/reports/income-expense');
    $response->assertRedirect('/login');
});

test('shows income, expense, and net totals for the selected range', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -300, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertViewHas('incomeTotal', 2000.0);
    $test->assertViewHas('expenseTotal', 300.0);
    $test->assertViewHas('netTotal', 1700.0);
});

test('excludes transfers from the totals', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -500, 'currency' => 'USD', 'created_at' => now(), 'type' => 'transfer']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertViewHas('incomeTotal', 2000.0);
    $test->assertViewHas('expenseTotal', 0.0);
});

test('excludes transactions from untracked accounts', function (): void {
    $user = User::factory()->create();
    makeAccountForIncomeExpenseReportTest($user, ['name' => 'Tracked']);
    $reference = makeAccountForIncomeExpenseReportTest($user, ['name' => 'Reference', 'tracking_mode' => 'reference']);
    Transaction::factory()->for($reference)->create(['name' => 'Should be excluded', 'amount' => 9999, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertViewHas('incomeTotal', 0.0);
});

test('shows a placeholder when there is nothing to chart', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertSee('Nothing to chart for the current date range.');
});

test('renders a trend chart once there is reportable activity', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertDontSee('Nothing to chart for the current date range.');
});

test('selecting categories switches the chart to a category breakdown', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    $groceries = Category::create(['name' => 'Groceries']);

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    $groceryTxn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -300, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);
    $groceryTxn->categories()->sync([$groceries->id]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');
    $test->set('category_ids', [$groceries->id]);

    $test->assertSet('chart_series', fn ($series): bool => count($series) === 1 && $series[0]['category_id'] === $groceries->id && array_sum($series[0]['values']) === 300.0);
});

test('selecting categories narrows the summary totals to just those categories', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    $groceries = Category::create(['name' => 'Groceries']);

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    $groceryTxn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -300, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);
    $groceryTxn->categories()->sync([$groceries->id]);
    // Uncategorized — should drop out once the filter narrows to Groceries.
    Transaction::factory()->for($account)->create(['name' => 'Rent', 'amount' => -800, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    // Unfiltered: everything counts.
    $test->assertViewHas('incomeTotal', 2000.0);
    $test->assertViewHas('expenseTotal', 1100.0);

    $test->set('category_ids', [$groceries->id]);

    // Filtered: only the Groceries-tagged expense counts.
    $test->assertViewHas('incomeTotal', 0.0);
    $test->assertViewHas('expenseTotal', 300.0);
});

test('clearing the category selection returns to the Income/Expense chart', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    $groceries = Category::create(['name' => 'Groceries']);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');
    $test->set('category_ids', [$groceries->id]);
    $test->set('category_ids', []);

    $test->assertSet('chart_series', fn ($series): bool => count($series) === 2 && $series[0]['label'] === 'Income' && $series[1]['label'] === 'Expense');
});

test('lists the transactions that make up the totals', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Groceries', 'amount' => -300, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertViewHas('transactionsList', fn ($list): bool => $list->total() === 2);
    $test->assertSee('Paycheck');
    $test->assertSee('Groceries');
});

test('the transaction list excludes transfers, matching the totals above it', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    Transaction::factory()->for($account)->create(['name' => 'Card Payment', 'amount' => -500, 'currency' => 'USD', 'created_at' => now(), 'type' => 'transfer']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');

    $test->assertViewHas('transactionsList', fn ($list): bool => $list->total() === 1);
    $test->assertDontSee('Card Payment');
});

test('the transaction list narrows to the selected category, matching the chart', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    $groceries = Category::create(['name' => 'Groceries']);

    Transaction::factory()->for($account)->create(['name' => 'Paycheck', 'amount' => 2000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'income']);
    $groceryTxn = Transaction::factory()->for($account)->create(['name' => 'Store', 'amount' => -300, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);
    $groceryTxn->categories()->sync([$groceries->id]);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');
    $test->set('category_ids', [$groceries->id]);

    $test->assertViewHas('transactionsList', fn ($list): bool => $list->total() === 1);
    $test->assertSee('Store');
    $test->assertDontSee('Paycheck');
});

test('the search filter narrows the totals and the transaction list together', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Whole Foods Market', 'amount' => -50, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'Rent Payment', 'amount' => -800, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');
    $test->set('search', 'whole foods');

    $test->assertViewHas('expenseTotal', 50.0);
    $test->assertViewHas('transactionsList', fn ($list): bool => $list->total() === 1);
    $test->assertSee('Whole Foods Market');
    $test->assertDontSee('Rent Payment');
});

test('the amount range filter narrows the totals and the transaction list together', function (): void {
    $user = User::factory()->create();
    $account = makeAccountForIncomeExpenseReportTest($user);
    Transaction::factory()->for($account)->create(['name' => 'Small', 'amount' => -10, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);
    Transaction::factory()->for($account)->create(['name' => 'Big', 'amount' => -1000, 'currency' => 'USD', 'created_at' => now(), 'type' => 'expense']);

    $this->actingAs($user);

    $test = Livewire::test('admin.reports.income-expense.index');
    $test->set('amount_min', '500');

    $test->assertViewHas('expenseTotal', 1000.0);
    $test->assertViewHas('transactionsList', fn ($list): bool => $list->total() === 1);
    $test->assertSee('Big');
    $test->assertDontSee('Small');
});
