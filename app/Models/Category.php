<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id', 'id');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class)->withPivot('id');
    }

    public function fullName(): Attribute
    {
        return new Attribute(
            get: function () {
                return $this->parent_id && $this->parent_id !== 0
                    ? ($this->parent ? $this->parent->fullName : 'Unknown').' > '.$this->name
                    : $this->name;
            },
        );
    }

    public function descendants(): Attribute
    {
        return new Attribute(
            get: function () {
                $descendants = [$this->id];

                $children = Category::where('parent_id', $this->id)->get();

                foreach ($children as $child) {
                    $descendants = array_merge($descendants, $child->descendants);
                }

                return $descendants;
            },
        );
    }

    public function descendantTransactions(): Attribute
    {
        // CACHE
        $id = $this->id;
        $descendants = $this->descendants;
        $transactions = Transaction::whereHas('categories', function ($query) use ($id, $descendants) {
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
     */
    public static function transferCategoryDescendantIds(): array
    {
        $transfers = Category::where('name', 'Transfers')->first();

        if (! $transfers) {
            return [];
        }

        return $transfers->descendants;
    }

    public function root($last_id = 0): Attribute
    {
        $cat = $this;
        while ($cat->id && $cat->parent_id != $last_id) {
            // dump($cat->id . ' - ' . $cat->name . ' -> ' . $cat->parent->id . ' - ' . $cat->parent->name);
            $cat = $cat->parent;
        }

        // dump($this->name . ' (' . $this->id . ') ==> ' . $cat->id);
        return new Attribute(
            get: fn () => $cat,
        );
    }
}
