<?php

declare(strict_types=1);

namespace App\Actions\Reports\Concerns;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared "simple" transaction filters used by Reports trend actions — a plain substring match on
 * name/merchant_name (not the richer required/excluded/optional parser used by Transaction
 * Search's full-text search) plus a magnitude range.
 */
trait FiltersBySearchAndAmount
{
    /**
     * @param  Builder<Transaction>  $query
     */
    private static function applySearchAndAmountFilters(Builder $query, string $search, string $amountMin, string $amountMax): void
    {
        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', $term)->orWhere('merchant_name', 'like', $term);
            });
        }

        if ($amountMin !== '') {
            // CAST is load-bearing: SQLite/PDO binds `?` with TEXT affinity, and comparing that
            // against a function-call expression like ABS(amount) (no column affinity of its
            // own) makes SQLite fall back to lexicographic string comparison — "1000" < "500"
            // alphabetically. Forcing both sides numeric avoids that (same pattern used in the
            // Transaction Search query).
            $query->whereRaw('ABS(amount) >= CAST(? AS REAL)', [(float) $amountMin]);
        }

        if ($amountMax !== '') {
            $query->whereRaw('ABS(amount) <= CAST(? AS REAL)', [(float) $amountMax]);
        }
    }
}
