<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Transaction::class, 'parent_id', 'id');
    }

     public function parent()
    {
        return $this->belongsTo(Transaction::class, 'parent_id');
    }

    public function scopeReportable($query)
    {
        $non_reportable_ids = Category::nonReportableIds();

        return $query
            ->where(function ($query) {
                $query
                    ->where(function ($q) {
                        $q->whereNull('parent_id')
                            ->where('is_split', false); // regular top-level
                    })
                    ->orWhereNotNull('parent_transaction_id'); // split children
            //})
            //->doesntHave('categories', function ($query) use ($non_reportable_ids) {
                //$query->whereIn('id', $non_reportable_ids);
            });
    }
}
