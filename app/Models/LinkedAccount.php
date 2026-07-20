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
