<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The core transaction-list query: IDOR-safe account scoping (aggregate views only ever pull from
 * accounts the given user actually owns), category/type/amount/date filtering, and an optional
 * relevance-scored search (see parseSearch()) supporting `required`, `-excluded`, and optional
 * terms.
 */
final class BuildTransactionsQueryAction
{
    /**
     * @return Builder<Transaction>
     */
    public static function run(User $user, TransactionFilters $filters): Builder
    {
        $query = Transaction::query();

        // Aggregate views (no specific account picked) only pull from tracked accounts —
        // reference/excluded accounts stay out of cross-account reports by design. Viewing a
        // single account directly (below) is unaffected.
        $ownedAccountIds = $user->accounts()->tracked()->pluck('accounts.id');

        if ($filters->account?->id) {
            Gate::forUser($user)->authorize('view', $filters->account);
            $query->where('account_id', $filters->account->id);
        } elseif ($filters->accountIds !== []) {
            // Only show transactions from selected accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds->intersect($filters->accountIds)->values());
        } else {
            // If no account is selected, only show transactions from accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds);
        }

        $query
            ->when($filters->originalCategoryId ?? false, fn ($query) => $query->where('original_category_id', $filters->originalCategoryId))
            ->when($filters->categoryId ?? false, function ($query) use ($filters) {
                $category = Category::find($filters->categoryId);
                $categoryId = $category->id;
                $descendants = $category->descendants;

                return $query->whereHas('categories', function ($query) use ($categoryId, $descendants): void {
                    $query
                        ->where('categories.id', $categoryId)
                        ->orWhereIn('categories.id', $descendants);
                });
            })
            ->when($filters->onlyUncategorized, fn ($query) => $query->doesntHave('categories'))
            ->when($filters->typeFilters !== [], fn ($query) => $query->whereIn('type', $filters->typeFilters))
            // Filtered on the transaction's magnitude, not its signed amount — a user thinking
            // "between $50 and $200" doesn't want to also have to know/guess the sign, and the
            // Type filter above already covers direction (income/expense/transfer/adjustment).
            // The CAST is load-bearing: SQLite/PDO binds `?` with TEXT affinity, and comparing
            // that against a function-call expression like ABS(amount) (which carries no column
            // affinity of its own) makes SQLite fall back to a lexicographic string comparison —
            // "1000" < "500" alphabetically — silently corrupting the filter for any value with a
            // different digit count. Forcing both sides numeric avoids that.
            ->when($filters->amountMin !== '', fn ($query) => $query->whereRaw('ABS(amount) >= CAST(? AS REAL)', [(float) $filters->amountMin]))
            ->when($filters->amountMax !== '', fn ($query) => $query->whereRaw('ABS(amount) <= CAST(? AS REAL)', [(float) $filters->amountMax]))
            ->with('categories')
            ->with('originalCategory')
            ->whereBetween('transactions.created_at', [$filters->dateFrom, $filters->dateTo]);

        if ($filters->search !== '' && $filters->search !== '0') {
            $terms = self::parseSearch($filters->search);

            // Dynamically build the relevance selectRaw
            $bindings = [];
            $scoreParts = [];

            foreach ($terms['optional'] as $term) {
                foreach (['transactions.name', 'transactions.merchant_name', 'original_categories.name', 'original_categories.pf_detailed'] as $field) {
                    $scoreParts[] = "CASE WHEN LOWER($field) LIKE ? THEN 1 ELSE 0 END";
                    $bindings[] = '%'.strtolower((string) $term).'%';
                }
            }

            if ($scoreParts !== []) {
                $scoreExpr = implode(' + ', $scoreParts);
                $query
                    ->leftJoin('original_categories', 'transactions.original_category_id', '=', 'original_categories.id')
                    ->selectRaw("transactions.*, ($scoreExpr) as relevance", $bindings);
            } else {
                $query
                    ->selectRaw('transactions.*, 0 as relevance');
            }

            $query->where(function ($q) use ($terms): void {
                $q->where(function ($q1) use ($terms): void {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term): void {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term): void {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term): void {
                        $q1->where('transactions.name', 'not like', '%'.$term.'%')
                            ->where(function ($q2) use ($term): void {
                                $q2->where('transactions.merchant_name', 'not like', '%'.$term.'%')
                                    ->orWhereNull('transactions.merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term): void {
                                $q2->where('name', 'like', '%'.$term.'%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term): void {
                                $q2->where('pf_detailed', 'like', '%'.$term.'%');
                            });
                    });
                }

            });
        } else {
            $query
                ->selectRaw('transactions.*, 0 as relevance');
        }

        return $query;
    }

    /**
     * @return array{required: array<int, string>, excluded: array<int, string>, optional: array<int, string>}
     */
    private static function parseSearch(string $query): array
    {
        preg_match_all('/([+-]?)"([^"]+)"|([+-]?)(\S+)/', $query, $matches, PREG_SET_ORDER);

        $parsed = [
            'required' => [],
            'excluded' => [],
            'optional' => [],
        ];

        foreach ($matches as $match) {
            $prefix = $match[1] ?: $match[3];
            $term = $match[2] ?: $match[4];

            switch ($prefix) {
                case '+':
                    $parsed['required'][] = $term;
                    break;
                case '-':
                    $parsed['excluded'][] = $term;
                    break;
                default:
                    $parsed['optional'][] = $term;
                    break;
            }
        }

        return $parsed;
    }
}
