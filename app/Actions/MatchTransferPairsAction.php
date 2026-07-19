<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class MatchTransferPairsAction
{
    private const float AMOUNT_TOLERANCE_PERCENT = 0.02; // absorbs FX spread/fees on cross-currency transfers

    private const int DATE_WINDOW_DAYS = 3;

    /**
     * Greedily pairs unpaired type=transfer transactions across different accounts.
     * Pairs spanning an investment/loan account are flagged, not auto-matched — Plaid's sign/type
     * conventions for those account types haven't been verified against real data yet.
     *
     * @param  Builder<Transaction>|null  $scope
     * @return array{matched_pairs: int, flagged_investment_or_loan: int}
     */
    public static function run(?Builder $scope = null): array
    {
        $candidates = ($scope ?? Transaction::query())
            ->where('type', 'transfer')
            ->whereNull('transfer_pair_id')
            ->with('account')
            ->get()
            ->sortBy(fn (Transaction $t) => $t->authorized_at ?? $t->created_at)
            ->values();

        $paired = [];
        $matched = 0;
        $flagged = 0;

        foreach ($candidates as $transaction) {
            if (in_array($transaction->id, $paired, true)) {
                continue;
            }

            $match = $candidates->first(fn (Transaction $candidate): bool => self::isCandidateMatch($transaction, $candidate, $paired));

            if ($match === null) {
                continue;
            }

            $spansRiskyAccountType = in_array($transaction->account?->type, ['investment', 'loan'], true)
                || in_array($match->account?->type, ['investment', 'loan'], true);

            if ($spansRiskyAccountType) {
                // Mark both as visited (without setting transfer_pair_id) so this same pair isn't
                // found and flagged again from the other direction later in this loop.
                $paired[] = $transaction->id;
                $paired[] = $match->id;
                $flagged++;

                continue;
            }

            $transaction->update(['transfer_pair_id' => $match->id]);
            $match->update(['transfer_pair_id' => $transaction->id]);

            $paired[] = $transaction->id;
            $paired[] = $match->id;
            $matched++;
        }

        return [
            'matched_pairs' => $matched,
            'flagged_investment_or_loan' => $flagged,
        ];
    }

    /**
     * @param  array<int>  $paired
     */
    private static function isCandidateMatch(Transaction $transaction, Transaction $candidate, array $paired): bool
    {
        if ($candidate->id === $transaction->id || in_array($candidate->id, $paired, true)) {
            return false;
        }

        // Never pair legs from the same account — this is what protects a same-account
        // refund from ever being mistaken for a transfer leg.
        if ($candidate->account_id === $transaction->account_id) {
            return false;
        }

        if (($candidate->amount > 0) === ($transaction->amount > 0)) {
            return false;
        }

        $amountDiffPercent = abs(abs($candidate->amount) - abs($transaction->amount)) / max(abs($transaction->amount), 0.01);
        if ($amountDiffPercent > self::AMOUNT_TOLERANCE_PERCENT) {
            return false;
        }

        // Compare calendar dates, not exact timestamps — two payments posted 3 days apart by
        // date but at different times of day (e.g. 00:00 vs 06:35) must still count as "3 days
        // apart", not 3.27, or a same-day time-of-day difference could push a real pair just
        // outside the window.
        $transactionDate = Carbon::parse($transaction->authorized_at ?? $transaction->created_at)->startOfDay();
        $candidateDate = Carbon::parse($candidate->authorized_at ?? $candidate->created_at)->startOfDay();

        return abs($transactionDate->diffInDays($candidateDate)) <= self::DATE_WINDOW_DAYS;
    }
}
