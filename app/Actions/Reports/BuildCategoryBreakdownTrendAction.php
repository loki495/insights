<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\BucketsIntoPeriods;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class BuildCategoryBreakdownTrendAction
{
    use BucketsIntoPeriods;

    /**
     * For each given category (matching it and its descendants, same convention as the rest of
     * the app), sums reportable() transaction amounts per period as a magnitude — these are
     * expense-side categories in practice, and a stacked area of signed amounts wouldn't compose
     * sensibly. A transaction tagged under more than one selected category contributes to each.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $categoryIds
     * @return array{periods: array<int, string>, series: array<int, array{category_id: int, label: string, color: string, values: array<int, float>}>}
     */
    public static function run(Collection $accounts, CarbonInterface $from, CarbonInterface $to, string $granularity, array $categoryIds): array
    {
        self::assertValidGranularity($granularity);

        $periods = self::periodBoundaries($from, $to, $granularity);
        $accountIds = $accounts->pluck('id');
        $categories = Category::whereIn('id', $categoryIds)->get()->keyBy('id');

        $series = [];

        foreach ($categoryIds as $categoryId) {
            $category = $categories->get($categoryId);

            if (! $category) {
                continue;
            }

            $transactions = Transaction::query()
                ->whereIn('account_id', $accountIds)
                ->reportable()
                ->whereBetween('created_at', [$from, $to])
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $category->descendants))
                ->orderBy('created_at')
                ->get(['created_at', 'amount']);

            $values = array_fill(0, count($periods), 0.0);

            $cursor = 0;
            foreach ($periods as $index => $period) {
                while ($cursor < $transactions->count() && $transactions[$cursor]->created_at->lte($period['end'])) {
                    $values[$index] += abs((float) $transactions[$cursor]->amount);
                    $cursor++;
                }
            }

            $series[] = [
                'category_id' => $category->id,
                'label' => $category->name,
                'color' => $category->color ?: '#3b82f6',
                'values' => $values,
            ];
        }

        return [
            'periods' => array_map(fn ($period) => $period['label'], $periods),
            'series' => $series,
        ];
    }
}
