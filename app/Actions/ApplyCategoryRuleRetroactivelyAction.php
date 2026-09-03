<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CategoryRule;
use Illuminate\Support\Facades\DB;

/**
 * User-initiated, one-rule-at-a-time backfill — deliberately not the heavier "re-scan every
 * rule against all history" concept from the original design research (which would need either
 * a queue worker this app has never stood up, or a slow synchronous full-table pass). Reuses
 * the exact same candidate set the rule editor's live preview already shows the user before
 * they click the button, so there's no surprise between "what you saw" and "what happened".
 *
 * Unlike the automatic sync-time engine (ApplyCategoryRulesAction), this deliberately has no
 * "first match wins across all rules" concept — it's scoped to the one rule the user is
 * looking at, and always assigns its category regardless of what other rules might also match.
 */
final class ApplyCategoryRuleRetroactivelyAction
{
    public static function run(CategoryRule $rule): int
    {
        return DB::transaction(function () use ($rule): int {
            $transactions = FindMatchingTransactionsForCategoryRuleAction::run($rule->user, $rule);

            foreach ($transactions as $transaction) {
                $transaction->categories()->sync([$rule->category_id]);
            }

            return $transactions->count();
        });
    }
}
