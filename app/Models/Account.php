<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * The user-chosen nickname if one is set, falling back to the name Plaid gave the account.
     *
     * @return Attribute<string, never>
     */
    public function displayName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nickname ?: $this->name,
        );
    }

    /**
     * @return BelongsTo<LinkedAccount, $this>
     */
    public function linked_account(): BelongsTo
    {
        return $this->belongsTo(LinkedAccount::class);
    }

    /**
     * Accounts the user actually wants folded into cross-account reports/totals — excludes
     * `reference` (manually-maintained placeholders) and `excluded` accounts. Viewing a single
     * account directly is unaffected by this; it only gates aggregate views.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('tracking_mode', 'tracked');
    }
}
