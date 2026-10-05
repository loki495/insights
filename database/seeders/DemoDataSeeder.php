<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\CreateOrAdoptCategoryAction;
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
     * Top-level category => [color, [subcategory => color]]. Each top level gets its own hue so the
     * dashboard's per-category chart and the transaction chips read as distinct groups.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const array CATEGORY_TREE = [
        'Income' => ['#16a34a', ['Paycheck' => '#22c55e', 'Freelance' => '#84cc16', 'Interest' => '#10b981']],
        'Food & Drink' => ['#f97316', ['Groceries' => '#fb923c', 'Restaurants' => '#ea580c', 'Coffee' => '#a16207']],
        'Housing' => ['#8b5cf6', ['Rent' => '#7c3aed', 'Utilities' => '#a78bfa', 'Home Improvement' => '#c4b5fd']],
        'Transportation' => ['#0ea5e9', ['Gas' => '#0284c7', 'Rideshare' => '#38bdf8', 'Public Transit' => '#7dd3fc']],
        'Shopping' => ['#ec4899', ['Online' => '#db2777', 'Electronics' => '#f472b6', 'Clothing' => '#f9a8d4']],
        'Entertainment' => ['#eab308', ['Subscriptions' => '#ca8a04', 'Events' => '#facc15', 'Games' => '#fde047']],
        'Health & Fitness' => ['#14b8a6', ['Pharmacy' => '#0d9488', 'Gym' => '#2dd4bf']],
        'Travel' => ['#6366f1', ['Flights' => '#4f46e5', 'Hotels' => '#818cf8']],
        'Pets' => ['#65a30d', []],
        'Transfers' => ['#64748b', []],
    ];

    /**
     * category => [account, [merchants...], [min, max] whole-dollar amount, [min, max] per-month count,
     * Plaid personal_finance_category primary, detailed]
     *
     * @var array<string, array{0: 'checking'|'card', 1: array<int, string>, 2: array{0: int, 1: int}, 3: array{0: int, 1: int}, 4: string, 5: string}>
     */
    private const array EXPENSE_BUCKETS = [
        'Groceries' => ['checking', ['Trader Joes', 'Whole Foods', 'Safeway', 'Kroger'], [15, 140], [3, 6], 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_GROCERIES'],
        'Restaurants' => ['card', ['Chipotle', 'Corner Diner', 'Pizza Palace', 'Sushi House'], [12, 85], [3, 6], 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_RESTAURANT'],
        'Coffee' => ['card', ['Blue Bottle', 'Starbucks', 'Local Roasters'], [4, 9], [3, 7], 'FOOD_AND_DRINK', 'FOOD_AND_DRINK_COFFEE'],
        'Rent' => ['checking', ['Parkview Apartments'], [1450, 1450], [1, 1], 'RENT_AND_UTILITIES', 'RENT_AND_UTILITIES_RENT'],
        'Utilities' => ['checking', ['City Power & Light', 'Metro Water', 'Comcast Internet'], [40, 160], [2, 3], 'RENT_AND_UTILITIES', 'RENT_AND_UTILITIES_GAS_AND_ELECTRICITY'],
        'Home Improvement' => ['card', ['Home Depot', 'IKEA'], [25, 240], [0, 2], 'HOME_IMPROVEMENT', 'HOME_IMPROVEMENT_HARDWARE'],
        'Gas' => ['checking', ['Shell', 'Chevron'], [30, 65], [2, 4], 'TRANSPORTATION', 'TRANSPORTATION_GAS'],
        'Rideshare' => ['card', ['Uber', 'Lyft'], [9, 42], [1, 4], 'TRANSPORTATION', 'TRANSPORTATION_TAXIS_AND_RIDE_SHARES'],
        'Public Transit' => ['checking', ['Metro Transit'], [3, 30], [1, 3], 'TRANSPORTATION', 'TRANSPORTATION_PUBLIC_TRANSIT'],
        'Online' => ['card', ['Amazon', 'Etsy'], [12, 140], [2, 4], 'GENERAL_MERCHANDISE', 'GENERAL_MERCHANDISE_ONLINE_MARKETPLACES'],
        'Electronics' => ['card', ['Best Buy', 'Apple Store'], [30, 400], [0, 1], 'GENERAL_MERCHANDISE', 'GENERAL_MERCHANDISE_ELECTRONICS'],
        'Clothing' => ['card', ['Uniqlo', 'Target', 'Nike'], [20, 120], [0, 2], 'GENERAL_MERCHANDISE', 'GENERAL_MERCHANDISE_CLOTHING_AND_ACCESSORIES'],
        'Events' => ['card', ['Ticketmaster', 'AMC Theatres'], [15, 120], [0, 2], 'ENTERTAINMENT', 'ENTERTAINMENT_SPORTING_EVENTS_AMUSEMENT_PARKS_AND_MUSEUMS'],
        'Games' => ['card', ['Steam', 'Nintendo eShop'], [10, 60], [0, 1], 'ENTERTAINMENT', 'ENTERTAINMENT_VIDEO_GAMES'],
        'Pharmacy' => ['card', ['CVS Pharmacy', 'Walgreens'], [8, 55], [0, 2], 'MEDICAL', 'MEDICAL_PHARMACIES_AND_SUPPLEMENTS'],
        'Gym' => ['card', ['Planet Fitness'], [25, 25], [1, 1], 'PERSONAL_CARE', 'PERSONAL_CARE_GYMS_AND_FITNESS_CENTERS'],
        'Flights' => ['card', ['Delta Air Lines', 'United Airlines'], [180, 460], [0, 1], 'TRAVEL', 'TRAVEL_FLIGHTS'],
        'Hotels' => ['card', ['Marriott', 'Airbnb'], [120, 380], [0, 1], 'TRAVEL', 'TRAVEL_LODGING'],
        'Pets' => ['card', ['Chewy', 'Petco'], [20, 90], [1, 2], 'GENERAL_MERCHANDISE', 'GENERAL_MERCHANDISE_OTHER_GENERAL_MERCHANDISE'],
    ];

    /** @var array<string, float> merchant => fixed monthly charge on the card */
    private const array SUBSCRIPTIONS = ['Netflix' => 15.49, 'Spotify' => 11.99, 'iCloud+' => 2.99];

    public function run(): void
    {
        // email_verified_at is deliberately not mass-assignable on User (a real user should never
        // be able to self-verify via a crafted request) — set directly instead for this trusted,
        // backend-only seeder.
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')]
        );

        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }

        if (LinkedAccount::where('user_id', $user->id)->where('is_demo', true)->exists()) {
            $this->command?->info('Demo data already exists for test@example.com — skipping.');

            return;
        }

        $original = $this->buildOriginalCategoryTaxonomy();
        $categories = $this->buildCategoryTree($user);

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
            // The current (partial) month can't backfill days that haven't happened yet -
            // capping the offset here keeps every generated date <= now, so a screenshot taken
            // mid-month never shows a "recent transaction" dated later than today.
            $maxDay = $monthsAgo === 0 ? now()->day - 1 : 27;

            $this->seedIncome($checking, $savings, $categories, $original, $monthStart, $maxDay);
            $this->seedExpenses(['checking' => $checking, 'card' => $creditCard], $categories, $original, $monthStart, $maxDay);
            $this->seedSubscriptions($creditCard, $categories['Subscriptions'], $original['Subscriptions'], $monthStart, $maxDay);
            $this->seedCreditCardPayment($checking, $creditCard, $categories['Transfers'], $original['CreditCardPayment'], $monthStart, $maxDay);
            $this->seedSavingsTransfer($checking, $savings, $categories['Transfers'], $original['Transfer'], $monthStart, $maxDay);
        }

        ReconcileLinkedAccountTransactions::run($linkedAccount);

        $this->command?->info('Seeded demo data for test@example.com / password.');
    }

    /**
     * @return array<string, OriginalCategory>
     */
    private function buildOriginalCategoryTaxonomy(): array
    {
        $original = [];

        foreach (self::EXPENSE_BUCKETS as $label => [, , , , $primary, $detailed]) {
            $original[$label] = upsertPlaidCategory(
                [Str::headline(strtolower($primary)), $label],
                'demo_'.Str::snake($label),
                ['primary' => $primary, 'detailed' => $detailed],
            );
        }

        return $original + [
            'Subscriptions' => upsertPlaidCategory(['Entertainment', 'Streaming Services'], 'demo_subscriptions', ['primary' => 'ENTERTAINMENT', 'detailed' => 'ENTERTAINMENT_TV_AND_MOVIES']),
            'Paycheck' => upsertPlaidCategory(['Income', 'Wages'], 'demo_income', ['primary' => 'INCOME', 'detailed' => 'INCOME_WAGES']),
            'Freelance' => upsertPlaidCategory(['Income', 'Other Income'], 'demo_freelance', ['primary' => 'INCOME', 'detailed' => 'INCOME_OTHER_INCOME']),
            'Interest' => upsertPlaidCategory(['Income', 'Interest Earned'], 'demo_interest', ['primary' => 'INCOME', 'detailed' => 'INCOME_INTEREST_EARNED']),
            'CreditCardPayment' => upsertPlaidCategory(['Loan Payments', 'Credit Card Payment'], 'demo_cc_payment', ['primary' => 'LOAN_PAYMENTS', 'detailed' => 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT']),
            'Transfer' => upsertPlaidCategory(['Transfer', 'Account Transfer'], 'demo_transfer', ['primary' => 'TRANSFER_OUT', 'detailed' => 'TRANSFER_OUT_ACCOUNT_TRANSFER']),
        ];
    }

    /**
     * Uses CreateOrAdoptCategoryAction (not a raw Category::create()) so re-running this seeder
     * against a database that already has these categories reuses them instead of creating fresh
     * duplicates, and so the demo user actually adopts everything it seeds — under the per-user
     * category model, its own transaction chips/reports wouldn't render colors or be browsable
     * otherwise. A top level with no subcategories is itself the leaf transactions are filed under.
     *
     * @return array<string, Category>
     */
    private function buildCategoryTree(User $user): array
    {
        $categories = [];

        foreach (self::CATEGORY_TREE as $name => [$color, $children]) {
            $parent = CreateOrAdoptCategoryAction::run($user, null, $name, $color);
            $categories[$name] = $parent;

            foreach ($children as $childName => $childColor) {
                $categories[$childName] = CreateOrAdoptCategoryAction::run($user, $parent->id, $childName, $childColor);
            }
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, OriginalCategory>  $original
     */
    private function seedIncome(Account $checking, Account $savings, array $categories, array $original, CarbonInterface $monthStart, int $maxDay): void
    {
        foreach ([0, 14] as $day) {
            if ($day <= $maxDay) {
                $this->record($checking, 'Acme Corp Payroll', random_int(2100, 2600), 'income', $categories['Paycheck'], $original['Paycheck'], $monthStart->copy()->addDays($day));
            }
        }

        if (random_int(0, 1) === 1) {
            $day = random_int(0, $maxDay);
            $this->record($checking, ['Upwork Payout', 'Stripe Transfer'][random_int(0, 1)], random_int(300, 1200), 'income', $categories['Freelance'], $original['Freelance'], $monthStart->copy()->addDays($day));
        }

        if ($maxDay >= 27) {
            $this->record($savings, 'Interest Payment', random_int(8, 25), 'income', $categories['Interest'], $original['Interest'], $monthStart->copy()->addDays(27));
        }
    }

    /**
     * @param  array{checking: Account, card: Account}  $accounts
     * @param  array<string, Category>  $categories
     * @param  array<string, OriginalCategory>  $original
     */
    private function seedExpenses(array $accounts, array $categories, array $original, CarbonInterface $monthStart, int $maxDay): void
    {
        foreach (self::EXPENSE_BUCKETS as $label => [$account, $merchants, [$min, $max], [$countMin, $countMax]]) {
            $count = random_int($countMin, $countMax);

            for ($i = 0; $i < $count; $i++) {
                // Leave ~15% of variable spending uncategorized on purpose — demo data should show
                // the "only uncategorized" filter finding something, not a fully-tagged fantasy.
                // Fixed-amount bills (rent, gym) always are, as a rule would catch them.
                $this->record(
                    $accounts[$account],
                    $merchants[array_rand($merchants)],
                    -random_int($min, $max),
                    'expense',
                    $min === $max || random_int(1, 100) > 15 ? $categories[$label] : null,
                    $original[$label],
                    $monthStart->copy()->addDays(random_int(0, $maxDay)),
                );
            }
        }
    }

    private function seedSubscriptions(Account $creditCard, Category $category, OriginalCategory $originalCategory, CarbonInterface $monthStart, int $maxDay): void
    {
        foreach (self::SUBSCRIPTIONS as $merchant => $price) {
            $day = (crc32($merchant) % 25) + 1;

            if ($day <= $maxDay) {
                $this->record($creditCard, $merchant, -$price, 'expense', $category, $originalCategory, $monthStart->copy()->addDays($day));
            }
        }
    }

    /**
     * Pays off the card's spending so far this month, so its balance doesn't drift up forever.
     */
    private function seedCreditCardPayment(Account $checking, Account $creditCard, Category $category, OriginalCategory $originalCategory, CarbonInterface $monthStart, int $maxDay): void
    {
        if ($maxDay < 20) {
            return;
        }

        $day = $monthStart->copy()->addDays(20);
        $spent = -Transaction::query()
            ->where('account_id', $creditCard->id)
            ->where('type', 'expense')
            ->whereBetween('created_at', [$monthStart, $monthStart->copy()->endOfMonth()])
            ->get()
            ->sum('amount'); // through MoneyCast: the column itself holds cents

        $outgoing = $this->record($checking, 'Rewards Card Payment', -round($spent, 2), 'transfer', $category, $originalCategory, $day);
        $incoming = $this->record($creditCard, 'Payment Thank You', round($spent, 2), 'transfer', $category, $originalCategory, $day);
        $outgoing->pairWith($incoming);
    }

    private function seedSavingsTransfer(Account $checking, Account $savings, Category $category, OriginalCategory $originalCategory, CarbonInterface $monthStart, int $maxDay): void
    {
        if ($maxDay < 2) {
            return;
        }

        $day = $monthStart->copy()->addDays(2);

        $outgoing = $this->record($checking, 'Transfer to Savings', -300, 'transfer', $category, $originalCategory, $day);
        $incoming = $this->record($savings, 'Transfer from Checking', 300, 'transfer', $category, $originalCategory, $day);
        $outgoing->pairWith($incoming);
    }

    private function record(Account $account, string $name, float|int $amount, string $type, ?Category $category, OriginalCategory $originalCategory, CarbonInterface $date): Transaction
    {
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'name' => $name,
            'amount' => $amount,
            'currency' => 'USD',
            'type' => $type,
            'original_category_id' => $originalCategory->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        if ($category instanceof Category) {
            $transaction->categories()->attach($category->id);
        }

        return $transaction;
    }
}
