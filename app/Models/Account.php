<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function linked_account() : BelongsTo
    {
        return $this->belongsTo(LinkedAccount::class);
    }
}
