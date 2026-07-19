<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OriginalCategory extends Model
{
    protected $fillable = [
        'name',
        'plaid_id',
        'parent_id',
        'pf_primary',
        'pf_detailed',
        'pf_confidence',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function total(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->transactions->sum('amount')
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get full category path as array (root → leaf)
     *
     * @return array<int, string>
     */
    public function getPathArrayAttribute(): array
    {
        $path = [];
        $current = $this;

        while ($current !== null) {
            array_unshift($path, $current->name);
            $current = $current->parent;
        }

        return $path;
    }

    /**
     * Get full category path as string
     */
    public function getFullPathAttribute(): string
    {
        return implode(' > ', $this->path_array);
    }
}
