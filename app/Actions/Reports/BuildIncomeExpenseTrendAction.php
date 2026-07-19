<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BuildIncomeExpenseTrendAction
{
    private const array GRANULARITIES = ['monthly', 'quarterly', 'yearly'];

    /**
     * Buckets reportable() transactions (excludes transfer/adjustment, per
     * Transaction::scopeReportable()) into periods, summing income and expense separately.
     * $categoryId is unused today but wired through so a category drilldown can be added later
     * without reworking this action.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{periods: array<int, string>, income: array<int, float>, expense: array<int, float>, net: array<int, float>}
     */
    public static function run(Collection $accounts, CarbonInterface $from, CarbonInterface $to, string $granularity, ?int $categoryId = null): array
    {
        if (! in_array($granularity, self::GRANULARITIES, true)) {
            throw new InvalidArgumentException('Invalid granularity.');
        }

        $periods = self::periodBoundaries($from, $to, $granularity);

        $query = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->reportable()
            ->whereBetween('created_at', [$from, $to]);

        if ($categoryId !== null) {
            $descendantIds = Category::findOrFail($categoryId)->descendants;
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $descendantIds));
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

    /**
     * @return array<int, array{label: string, end: CarbonInterface}>
     */
    private static function periodBoundaries(CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $boundaries = [];

        $cursor = match ($granularity) {
            'monthly' => $from->copy()->startOfMonth(),
            'quarterly' => $from->copy()->startOfQuarter(),
            'yearly' => $from->copy()->startOfYear(),
        };

        while ($cursor->lte($to)) {
            $label = match ($granularity) {
                'monthly' => $cursor->format('M Y'),
                'quarterly' => 'Q'.$cursor->quarter.' '.$cursor->format('Y'),
                'yearly' => $cursor->format('Y'),
            };

            $end = match ($granularity) {
                'monthly' => $cursor->copy()->endOfMonth(),
                'quarterly' => $cursor->copy()->endOfQuarter(),
                'yearly' => $cursor->copy()->endOfYear(),
            };

            $boundaries[] = [
                'label' => $label,
                'end' => $end->greaterThan($to) ? $to->copy() : $end,
            ];

            $cursor = match ($granularity) {
                'monthly' => $cursor->copy()->addMonth(),
                'quarterly' => $cursor->copy()->addQuarter(),
                'yearly' => $cursor->copy()->addYear(),
            };
        }

        return $boundaries;
    }
}
