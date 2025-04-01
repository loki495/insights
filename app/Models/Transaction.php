<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    public $casts = [
        'original' => 'json',
    ];

    /**
    * @return BelongsTo<Account>
    */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
    * @return BelongsTo<OriginalCategory>
    */
    public function originalCategory(): BelongsTo
    {
        return $this->belongsTo(OriginalCategory::class);
    }
}
