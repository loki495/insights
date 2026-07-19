<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\BucketsIntoPeriods;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class BuildIncomeExpenseTrendAction
{
    use BucketsIntoPeriods;

    /**
     * Buckets reportable() transactions (excludes transfer/adjustment, per
     * Transaction::scopeReportable()) into periods, summing income and expense separately.
     * When $categoryIds is given, only transactions tagged under any of those categories (or
     * their descendants) count — a transaction matching more than one selected category is still
     * only counted once here (this produces a single total, unlike the per-category breakdown
     * action where the same transaction legitimately contributes to each of its own series).
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $categoryIds
     * @return array{periods: array<int, string>, income: array<int, float>, expense: array<int, float>, net: array<int, float>}
     */
    public static function run(Collection $accounts, CarbonInterface $from, CarbonInterface $to, string $granularity, array $categoryIds = []): array
    {
        self::assertValidGranularity($granularity);

        $periods = self::periodBoundaries($from, $to, $granularity);

        $query = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->reportable()
            ->whereBetween('created_at', [$from, $to]);

        if ($categoryIds !== []) {
            $matchingIds = Category::whereIn('id', $categoryIds)->get()
                ->flatMap(fn (Category $category) => $category->descendants)
                ->unique()
                ->values();

            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $matchingIds));
        }

        $transactions = $query->orderBy('created_at')->get(['created_at', 'amount']);

        $income = array_fill(0, count($periods), 0.0);
        $expense = array_fill(0, count($periods), 0.0);

        $cursor = 0;
        foreach ($periods as $index => $period) {
            while ($cursor < $transactions->count() && $transactions[$cursor]->created_at->lte($period['end'])) {
                $amount = (float) $transactions[$cursor]->amount;

                if ($amount > 0) {
                    $income[$index] += $amount;
                } else {
                    $expense[$index] += abs($amount);
                }

                $cursor++;
            }
        }

        $net = array_map(fn ($i, $e) => $i - $e, $income, $expense);

        return [
            'periods' => array_map(fn ($period) => $period['label'], $periods),
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
        ];
    }
}
