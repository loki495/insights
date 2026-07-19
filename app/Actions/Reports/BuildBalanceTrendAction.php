<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BuildBalanceTrendAction
{
    private const array GRANULARITIES = ['monthly', 'quarterly', 'yearly'];

    /**
     * Account types treated as liabilities (subtracted from Net); everything else is an asset.
     */
    private const array LIABILITY_TYPES = ['credit', 'loan'];

    /**
     * Builds a Net Cash trend: for each period boundary in [$from, $to], each account's balance is
     * the running_balance of its latest transaction at/before that boundary. Accounts with no
     * transaction yet by a given boundary are left out of that period's totals entirely — never
     * fabricated as zero, since a $0 balance and "doesn't exist yet" mean very different things.
     * Once an account has activity, gaps with no further activity carry the last known balance
     * forward (nothing changed the balance, so nothing changed).
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{periods: array<int, string>, assets: array<int, float>, liabilities: array<int, float>, net: array<int, float>}
     */
    public static function run(Collection $accounts, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        if (! in_array($granularity, self::GRANULARITIES, true)) {
            throw new InvalidArgumentException('Invalid granularity.');
        }

        $boundaries = self::periodBoundaries($from, $to, $granularity);

        // For each account, the transactions needed to answer "balance as of date X" for every X —
        // ordered ascending so we can walk them alongside the (also ascending) period boundaries.
        $transactionsByAccount = $accounts
            ->mapWithKeys(fn (Account $account) => [
                $account->id => $account->transactions()
                    ->orderBy('created_at')
                    ->get(['created_at', 'running_balance']),
            ]);

        $assets = array_fill(0, count($boundaries), 0.0);
        $liabilities = array_fill(0, count($boundaries), 0.0);

        foreach ($accounts as $account) {
            $transactions = $transactionsByAccount[$account->id];
            $isLiability = in_array($account->type, self::LIABILITY_TYPES, true);

            $cursor = 0;
            $balanceAsOf = null;

            foreach ($boundaries as $index => $boundary) {
                while ($cursor < $transactions->count() && $transactions[$cursor]->created_at->lte($boundary['end'])) {
                    $balanceAsOf = (float) $transactions[$cursor]->running_balance;
                    $cursor++;
                }

                if ($balanceAsOf === null) {
                    continue;
                }

                if ($isLiability) {
                    $liabilities[$index] += $balanceAsOf;
                } else {
                    $assets[$index] += $balanceAsOf;
                }
            }
        }

        $net = array_map(fn ($asset, $liability) => $asset - $liability, $assets, $liabilities);

        return [
            'periods' => array_map(fn ($boundary) => $boundary['label'], $boundaries),
            'assets' => $assets,
            'liabilities' => $liabilities,
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
