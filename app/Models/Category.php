<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property-read string $fullName
 * @property-read array<int, int> $descendants
 * @property-read Collection<int, Transaction> $descendantTransactions
 * @property-read self $root
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id', 'id');
    }

    /**
     * @return BelongsToMany<Transaction, $this>
     */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class)->withPivot('id');
    }

    /**
     * @return Attribute<string, never>
     */
    public function fullName(): Attribute
    {
        return new Attribute(
            get: fn () => $this->parent_id
                ? ($this->parent ? $this->parent->fullName : 'Unknown').' > '.$this->name
                : $this->name,
        );
    }

    /**
     * @return Attribute<array<int, int>, never>
     */
    public function descendants(): Attribute
    {
        return new Attribute(
            get: function (): array {
                $descendants = [$this->id];

                $children = Category::where('parent_id', $this->id)->get();

                foreach ($children as $child) {
                    $descendants = array_merge($descendants, $child->descendants);
                }

                return $descendants;
            },
        );
    }

    /**
     * @return Attribute<Collection<int, Transaction>, never>
     */
    public function descendantTransactions(): Attribute
    {
        // CACHE
        $id = $this->id;
        $descendants = $this->descendants;
        $transactions = Transaction::whereHas('categories', function ($query) use ($id, $descendants): void {
            $query
                ->where('categories.id', $id)
                ->orWhereIn('categories.id', $descendants);
        })
            ->get();

        return new Attribute(
            get: fn () => $transactions,
        );
    }

    /**
     * Ids of the "Transfers" category and its descendants, if that convention exists in this
     * install's category tree. Used only as an additional signal when classifying transaction
     * `type` (see Transaction::refreshType()) — not the source of truth for report filtering,
     * which is driven by `type` directly.
     *
     * @return array<int, int>
     */
    public static function transferCategoryDescendantIds(): array
    {
        $transfers = Category::where('name', 'Transfers')->first();

        if (! $transfers) {
            return [];
        }

        return $transfers->descendants;
    }

    /**
     * @return Attribute<self, never>
     */
    public function root(?int $last_id = 0): Attribute
    {
        $cat = $this;
        while ($cat->id && $cat->parent_id != $last_id) {
            $cat = $cat->parent;
        }

        return new Attribute(
            get: fn () => $cat,
        );
    }
}
