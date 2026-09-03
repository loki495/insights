<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared by the rule editor's live preview (against a possibly-unsaved, in-memory CategoryRule
 * built from the current form state) and ApplyCategoryRuleRetroactivelyAction (against a real,
 * persisted one) — both need exactly the same "which of this user's own uncategorized
 * transactions would this rule match" answer.
 *
 * Brute-forced in PHP against a fully-materialized collection rather than a dynamic
 * per-condition SQL query, same reasoning as the rest of this rule engine: simple and correct,
 * revisit if this app's transaction volume ever makes it matter (it's a self-hosted
 * personal-finance tool, not built for that scale today).
 */
final class FindMatchingTransactionsForCategoryRuleAction
{
    /**
     * @return Collection<int, Transaction>
     */
    public static function run(User $user, CategoryRule $rule): Collection
    {
        return Transaction::query()
            ->whereIn('account_id', $user->accounts()->pluck('accounts.id'))
            ->whereDoesntHave('categories')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Transaction $transaction): bool => $rule->matches($transaction))
            ->values();
    }
}
