<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\BucketsIntoPeriods;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class BuildBalanceTrendAction
{
    use BucketsIntoPeriods;

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
        self::assertValidGranularity($granularity);

        $boundaries = self::periodBoundaries($from, $to, $granularity);

        // The transactions needed to answer "balance as of date X" for every X — ordered
        // ascending so we can walk them alongside the (also ascending) period boundaries.
        //
        // One query for every account rather than one per account: this runs on every
        // dashboard load, so the previous per-account lazy load was an N+1 that scaled
        // with how many accounts are linked.
        //
        // Bounded at the top end only. A transaction after $to can never affect a balance
        // "as of" any boundary in range, so dropping those is free. The bottom end is
        // deliberately NOT bounded: the first boundary's balance is the running_balance of
        // the latest transaction at/before it, which may predate $from by any amount, and
        // filtering those out would report an account as "no activity yet" instead of
        // carrying its real balance forward (see this action's two dedicated tests).
        $transactionsByAccount = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->get(['account_id', 'created_at', 'running_balance'])
            ->groupBy('account_id')
            ->map(fn (Collection $accountTransactions): Collection => $accountTransactions->values());

        $assets = array_fill(0, count($boundaries), 0.0);
        $liabilities = array_fill(0, count($boundaries), 0.0);

        foreach ($accounts as $account) {
            // An account with no transactions at all has no group, unlike the previous
            // per-account query which always returned an (empty) collection.
            $transactions = $transactionsByAccount->get($account->id) ?? collect();
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

        $net = array_map(fn (float $asset, float $liability): float => $asset - $liability, $assets, $liabilities);

        return [
            'periods' => array_map(fn (array $boundary): string => $boundary['label'], $boundaries),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'net' => $net,
        ];
    }
}
