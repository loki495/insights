<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LinkedAccount extends Model
{
    /** @use HasFactory<\Database\Factories\LinkedAccountFactory> */
    use HasFactory;

    public $casts = [
        'access_token' => 'encrypted',
    ];

    /**
     * @return BelongsTo
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updateInfo() : self
    {
        $plaid = plaid();
        $info = $plaid->getItemInfo(data: [
            'access_token' => $this->access_token
        ]);

        $this->provider_name = $info['item']['institution_name'];

        $this->save();

        return $this;

    }

    /**
     * @return HasMany<Account>
     */
    public function accounts() : HasMany
    {
        return $this->hasMany(Account::class);
    }
}
