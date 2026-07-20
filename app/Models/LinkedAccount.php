<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LinkedAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkedAccount extends Model
{
    /** @use HasFactory<LinkedAccountFactory> */
    use HasFactory;

    public $casts = [
        'access_token' => 'encrypted',
        'closed_at' => 'datetime',
        'is_demo' => 'boolean',
        'auto_pull_enabled' => 'boolean',
        'last_pulled_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function autoPullIntervalInHours(): int
    {
        return $this->auto_pull_interval_unit === 'days'
            ? $this->auto_pull_interval_value * 24
            : $this->auto_pull_interval_value;
    }

    /**
     * Whether a scheduled pull is due — separate from `isClosed()`/manual "Pull Data", which are
     * unaffected by this setting.
     */
    public function isAutoPullDue(): bool
    {
        if (! $this->auto_pull_enabled || $this->isClosed()) {
            return false;
        }

        if (! $this->last_pulled_at) {
            return true;
        }

        return $this->last_pulled_at->lte(now()->subHours($this->autoPullIntervalInHours()));
    }

    public function updateInfo(): self
    {
        $plaid = plaid();
        $info = $plaid->getItemInfo(data: [
            'access_token' => $this->access_token,
        ]);

        $this->provider_name = $info['item']['institution_name'];

        $this->save();

        return $this;

    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
