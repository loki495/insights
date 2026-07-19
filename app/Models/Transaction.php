<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public $casts = [
        'original' => 'json',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<OriginalCategory, $this>
     */
    public function originalCategory(): BelongsTo
    {
        return $this->belongsTo(OriginalCategory::class);
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Transaction::class, 'parent_id', 'id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_pair_id');
    }

    /**
     * Everything except positively-identified transfers/adjustments. A transaction with no
     * `type` yet (never classified) stays reportable by default rather than silently vanishing
     * from totals — SQL's `NOT IN` excludes NULLs on its own, so that's made explicit here.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeReportable(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->whereNotIn('type', ['transfer', 'adjustment'])
                ->orWhereNull('type');
        });
    }

    /**
     * Best-guess type from Plaid's own personal-finance-category data, plus amount sign as a fallback.
     * Does not consider the app's own Category tags — see refreshType() for that layer.
     */
    public static function classifyType(?OriginalCategory $category, int|float $amount): string
    {
        $primary = $category?->pf_primary;
        $detailed = $category?->pf_detailed;

        if (in_array($primary, ['TRANSFER_IN', 'TRANSFER_OUT'], true)) {
            return 'transfer';
        }

        // Plaid buckets credit-card payments under LOAN_PAYMENTS, not its own TRANSFER_* primary —
        // but paying a card is functionally a transfer once both sides are tracked.
        if ($detailed === 'LOAN_PAYMENTS_CREDIT_CARD_PAYMENT') {
            return 'transfer';
        }

        if ($primary === 'INCOME') {
            return 'income';
        }

        return $amount > 0 ? 'income' : 'expense';
    }

    /**
     * Recompute and persist `type`, layering the app's own "Transfers" category tag (if any)
     * on top of the Plaid-derived guess from classifyType().
     */
    public function refreshType(): void
    {
        $type = static::classifyType($this->originalCategory, $this->amount);

        $transferCategoryIds = Category::transferCategoryDescendantIds();
        if ($transferCategoryIds !== [] && $this->categories()->whereIn('categories.id', $transferCategoryIds)->exists()) {
            $type = 'transfer';
        }

        if ($this->type !== $type) {
            $this->update(['type' => $type]);
        }
    }

    /**
     * Links two transactions as the two legs of the same transfer. Never allowed across the
     * same account — that's what protects a same-account refund from being mistaken for a
     * transfer leg (see MatchTransferPairsAction, which enforces the same rule automatically).
     */
    public function pairWith(self $other): void
    {
        if ($other->account_id === $this->account_id) {
            throw new \InvalidArgumentException('Cannot pair two transactions from the same account.');
        }

        $this->update(['transfer_pair_id' => $other->id]);
        $other->update(['transfer_pair_id' => $this->id]);
    }

    /**
     * Clears the pairing on both legs, if any.
     */
    public function unpair(): void
    {
        if ($this->transfer_pair_id) {
            static::where('id', $this->transfer_pair_id)->update(['transfer_pair_id' => null]);
        }

        $this->update(['transfer_pair_id' => null]);
    }

    /**
     * Unpaired, opposite-account transfer transactions matching a search term — candidates for
     * manually pairing $excludeTransactionId with the correct other leg.
     *
     * @return EloquentCollection<int, static>
     */
    public static function searchUnpairedTransferCandidates(int $excludeTransactionId, int $excludeAccountId, string $search): EloquentCollection
    {
        if (trim($search) === '') {
            return new EloquentCollection;
        }

        return static::query()
            ->where('id', '!=', $excludeTransactionId)
            ->where('account_id', '!=', $excludeAccountId)
            ->where('type', 'transfer')
            ->whereNull('transfer_pair_id')
            ->where(function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where('name', 'like', $term)
                    ->orWhere('merchant_name', 'like', $term);
            })
            ->with('account')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }
}
