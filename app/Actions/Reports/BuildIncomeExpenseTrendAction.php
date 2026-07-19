<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\BucketsIntoPeriods;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class BuildIncomeExpenseTrendAction
{
    use BucketsIntoPeriods;

    /**
     * Buckets reportable() transactions (excludes transfer/adjustment, per
     * Transaction::scopeReportable()) into periods, summing income and expense separately.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{periods: array<int, string>, income: array<int, float>, expense: array<int, float>, net: array<int, float>}
     */
    public static function run(Collection $accounts, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        self::assertValidGranularity($granularity);

        $periods = self::periodBoundaries($from, $to, $granularity);

        $transactions = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->reportable()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get(['created_at', 'amount']);

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
