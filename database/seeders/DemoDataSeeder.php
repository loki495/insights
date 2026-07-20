<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\ReconcileLinkedAccountTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Populates a realistic-looking, randomized demo dataset for anyone exploring the app without a
 * real Plaid account — not wired into DatabaseSeeder's default run, since real users shouldn't
 * get fake transactions on a plain `db:seed`. Run explicitly:
 * `php artisan db:seed --class=DemoDataSeeder`. Idempotent: re-running it against an
 * already-seeded demo user is a no-op rather than piling up duplicate accounts.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * label => [type, [merchant names...], [min, max] whole-dollar amount magnitude, per-month count]
     *
     * @var array<string, array{0: string, 1: array<int, string>, 2: array{0: int, 1: int}, 3: array{0: int, 1: int}}>
     */
    private const EXPENSE_BUCKETS = [
        'Groceries' => ['expense', ['Trader Joes', 'Whole Foods', 'Safeway', 'Kroger'], [15, 140], [3, 6]],
        'Dining' => ['expense', ['Chipotle', 'Corner Diner', 'Pizza Palace', 'Sushi House'], [10, 65], [2, 5]],
        'Utilities' => ['expense', ['City Power & Light', 'Metro Water', 'Comcast Internet'], [40, 180], [2, 3]],
        'Transportation' => ['expense', ['Shell Gas Station', 'Metro Transit', 'Uber'], [8, 70], [2, 5]],
        'Shopping' => ['expense', ['Amazon', 'Target', 'Best Buy'], [12, 220], [2, 4]],
    ];

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password'), 'email_verified_at' => now()]
        );

        if (LinkedAccount::where('user_id', $user->id)->where('is_demo', true)->exists()) {
            $this->command?->info('Demo data already exists for test@example.com — skipping.');

            return;
        }

        $original = $this->buildOriginalCategoryTaxonomy();
        $categories = $this->buildCategoryTree();

        $linkedAccount = LinkedAccount::create([
            'user_id' => $user->id,
            'item_id' => 'demo_item_'.Str::random(16),
            'provider_name' => 'Demo Bank',
            'access_token' => 'demo-'.Str::random(24),
            'is_demo' => true,
        ]);

        $checking = Account::create([
            'linked_account_id' => $linkedAccount->id,
            'plaid_account_id' => 'demo_checking_'.Str::random(8),
            'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Demo Checking',
            'type' => 'depository', 'subtype' => 'checking', 'currency' => 'USD',
            'current_balance' => 3200, 'tracking_mode' => 'tracked',
        ]);
        $savings = Account::create([
            'linked_account_id' => $linkedAccount->id,
            'plaid_account_id' => 'demo_savings_'.Str::random(8),
            'mask' => '1111', 'name' => 'Savings', 'official_name' => 'Demo Savings',
            'type' => 'depository', 'subtype' => 'savings', 'currency' => 'USD',
            'current_balance' => 9500, 'tracking_mode' => 'tracked',
        ]);
        $creditCard = Account::create([
            'linked_account_id' => $linkedAccount->id,
            'plaid_account_id' => 'demo_credit_'.Str::random(8),
            'mask' => '2222', 'name' => 'Rewards Card', 'official_name' => 'Demo Rewards Card',
            'type' => 'credit', 'subtype' => 'credit card', 'currency' => 'USD',
            'current_balance' => 480, 'tracking_mode' => 'tracked',
        ]);

        $months = 6;
        for ($monthsAgo = $months - 1; $monthsAgo >= 0; $monthsAgo--) {
            $monthStart = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth();

            $this->seedPaycheck($checking, $categories['Paycheck'], $original['Paycheck'], $monthStart);
            $this->seedExpenses($checking, $categories, $original, $monthStart);
            $this->seedCreditCardActivity($creditCard, $categories['Entertainment'], $original['Entertainment'], $monthStart);
            $this->seedCreditCardPayment($checking, $creditCard, $original['CreditCardPayment'], $monthStart);
            $this->seedSavingsTransfer($checking, $savings, $original['Transfer'], $monthStart);
        }

        ReconcileLinkedAccountTransactions::run($linkedAccount);

        $this->command?->info('Seeded demo data for test@example.com / password.');
    }

    /**
     * @return array<string, OriginalCategory>
     */
    private function buildOriginalCategoryTaxonomy(): array
    {
        return [
            'Groceries' => upsertPlaidCategory(['Food and Drink', 'Groceries'], 'demo_groceries', ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_GROCERIES']),
            'Dining' => upsertPlaidCategory(['Food and Drink', 'Restaurants'], 'demo_dining', ['primary' => 'FOOD_AND_DRINK', 'detailed' => 'FOOD_AND_DRINK_RESTAURANT']),
            'Utilities' => upsertPlaidCategory(['Rent and Utilities', 'Gas and Electricity'], 'demo_utilities', ['primary' => 'RENT_AND_UTILITIES', 'detailed' => 'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY']),
            'Transportation' => upsertPlaidCategory(['Transportation', 'Gas'], 'demo_transportation', ['primary' => 'TRANSPORTATION', 'detailed' => 'TRANSPORTATION_GAS']),
            'Shopping' => upsertPlaidCategory(['General Merchandise', 'Online Marketplaces'], 'demo_shopping', ['primary' => 'GENERAL_MERCHANDISE', 'detailed' => 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES']),
            'Entertainment' => upsertPlaidCategory(['Entertainment', 'Streaming Services'], 'demo_entertainment', ['primary' => 'ENTERTAINMENT', 'detailed' => 'ENTERTAINMENT_TV_AND_MOVIES']),
            'Paycheck' => upsertPlaidCategory(['Income', 'Wages'], 'demo_income', ['primary' => 'INCOME', 'detailed' => 'INCOME_WAGES']),
            'CreditCardPayment' => upsertPlaidCategory(['Loan Payments', 'Credit Card Payment'], 'demo_cc_payment', ['primary' => 'LOAN_PAYMENTS', 'detailed' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT']),
            'Transfer' => upsertPlaidCategory(['Transfer', 'Account Transfer'], 'demo_transfer', ['primary' => 'TRANSFER_OUT', 'detailed' => 'TRANSFER_OUT_ACCOUNT_TRANSFER']),
        ];
    }

    /**
     * @return array<string, Category>
     */
    private function buildCategoryTree(): array
    {
        $income = Category::create(['name' => 'Income', 'color' => '#16a34a']);
        $expenses = Category::create(['name' => 'Expenses', 'color' => '#dc2626']);

        return [
            'Paycheck' => Category::create(['name' => 'Paycheck', 'parent_id' => $income->id]),
            'Groceries' => Category::create(['name' => 'Groceries', 'parent_id' => $expenses->id]),
            'Dining' => Category::create(['name' => 'Dining', 'parent_id' => $expenses->id]),
            'Utilities' => Category::create(['name' => 'Utilities', 'parent_id' => $expenses->id]),
            'Transportation' => Category::create(['name' => 'Transportation', 'parent_id' => $expenses->id]),
            'Shopping' => Category::create(['name' => 'Shopping', 'parent_id' => $expenses->id]),
            'Entertainment' => Category::create(['name' => 'Entertainment', 'parent_id' => $expenses->id]),
        ];
    }

    private function seedPaycheck(Account $checking, Category $category, OriginalCategory $originalCategory, CarbonInterface $monthStart): void
    {
        foreach ([1, 15] as $dayOfMonth) {
            $transaction = Transaction::create([
                'account_id' => $checking->id,
                'name' => 'Acme Corp Payroll',
                'amount' => random_int(2100, 2600),
                'currency' => 'USD',
                'type' => 'income',
                'original_category_id' => $originalCategory->id,
                'created_at' => $monthStart->copy()->addDays($dayOfMonth - 1),
                'updated_at' => $monthStart->copy()->addDays($dayOfMonth - 1),
            ]);
            $transaction->categories()->attach($category->id);
        }
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, OriginalCategory>  $original
     */
    private function seedExpenses(Account $checking, array $categories, array $original, CarbonInterface $monthStart): void
    {
        foreach (self::EXPENSE_BUCKETS as $label => [, $merchants, [$min, $max], [$countMin, $countMax]]) {
            $count = random_int($countMin, $countMax);

            for ($i = 0; $i < $count; $i++) {
                $transaction = Transaction::create([
                    'account_id' => $checking->id,
                    'name' => $merchants[array_rand($merchants)],
                    'amount' => -random_int($min, $max),
                    'currency' => 'USD',
                    'type' => 'expense',
                    'original_category_id' => $original[$label]->id,
                    'created_at' => $monthStart->copy()->addDays(random_int(0, 27)),
                    'updated_at' => $monthStart->copy()->addDays(random_int(0, 27)),
                ]);

                // Leave ~15% uncategorized on purpose — demo data should show the "only
                // uncategorized" filter actually finding something, not a fully-tagged fantasy.
                if (random_int(1, 100) > 15) {
                    $transaction->categories()->attach($categories[$label]->id);
                }
            }
        }
    }

    private function seedCreditCardActivity(Account $creditCard, Category $entertainmentCategory, OriginalCategory $originalCategory, CarbonInterface $monthStart): void
    {
        foreach (['Netflix', 'Spotify'] as $merchant) {
            $transaction = Transaction::create([
                'account_id' => $creditCard->id,
                'name' => $merchant,
                'amount' => -random_int(10, 20),
                'currency' => 'USD',
                'type' => 'expense',
                'original_category_id' => $originalCategory->id,
                'created_at' => $monthStart->copy()->addDays(random_int(0, 27)),
                'updated_at' => $monthStart->copy()->addDays(random_int(0, 27)),
            ]);
            $transaction->categories()->attach($entertainmentCategory->id);
        }
    }

    private function seedCreditCardPayment(Account $checking, Account $creditCard, OriginalCategory $originalCategory, CarbonInterface $monthStart): void
    {
        $day = $monthStart->copy()->addDays(20);

        $outgoing = Transaction::create([
            'account_id' => $checking->id,
            'name' => 'Rewards Card Payment',
            'amount' => -random_int(80, 150),
            'currency' => 'USD',
            'type' => 'transfer',
            'original_category_id' => $originalCategory->id,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
        $incoming = Transaction::create([
            'account_id' => $creditCard->id,
            'name' => 'Payment Thank You',
            'amount' => -$outgoing->amount,
            'currency' => 'USD',
            'type' => 'transfer',
            'original_category_id' => $originalCategory->id,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
        $outgoing->pairWith($incoming);
    }

    private function seedSavingsTransfer(Account $checking, Account $savings, OriginalCategory $originalCategory, CarbonInterface $monthStart): void
    {
        $day = $monthStart->copy()->addDays(2);

        $outgoing = Transaction::create([
            'account_id' => $checking->id,
            'name' => 'Transfer to Savings',
            'amount' => -300,
            'currency' => 'USD',
            'type' => 'transfer',
            'original_category_id' => $originalCategory->id,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
        $incoming = Transaction::create([
            'account_id' => $savings->id,
            'name' => 'Transfer from Checking',
            'amount' => 300,
            'currency' => 'USD',
            'type' => 'transfer',
            'original_category_id' => $originalCategory->id,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
        $outgoing->pairWith($incoming);
    }
}
