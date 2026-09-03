<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CategoryRule;
use App\Models\Transaction;

/**
 * Only ever called for a transaction with no category yet (see
 * UpdateAccountTransactionsAction) — a rule must never override a manual categorization on
 * re-sync.
 */
final class ApplyCategoryRulesAction
{
    public static function run(Transaction $transaction): void
    {
        $rule = $transaction->user->categoryRules()
            ->where('active', true)
            ->with('conditionGroups.conditions')
            ->orderBy('priority')
            ->get()
            ->first(fn (CategoryRule $rule): bool => $rule->matches($transaction));

        if ($rule) {
            $transaction->categories()->sync([$rule->category_id]);
        }
    }
}
