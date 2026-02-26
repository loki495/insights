<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
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

    protected static array $nameCache = [];

    public function fullName(): Attribute
    {
        return new Attribute(
            get: function () {
                if (isset(static::$nameCache[$this->id])) {
                    return static::$nameCache[$this->id];
                }

                $name = $this->parent_id && $this->parent_id !== 0
                    ? ($this->parent ? $this->parent->fullName : 'Unknown') . ' > ' . $this->name
                    : $this->name;

                return static::$nameCache[$this->id] = $name;
            },
        );
    }

    protected static array $descendantsCache = [];

    public function descendants(): Attribute
    {
        return new Attribute(
            get: function () {
                if (isset(static::$descendantsCache[$this->id])) {
                    return static::$descendantsCache[$this->id];
                }

                $descendants = [$this->id];

                // Get children without triggering extra recursion via attribute if possible
                $children = Category::where('parent_id', $this->id)->get();

                foreach ($children as $child) {
                    $descendants = array_merge($descendants, $child->descendants);
                }

                return static::$descendantsCache[$this->id] = $descendants;
            },
        );
    }

    public function descendantTransactions(): Attribute
    {
        // CACHE
        $id = $this->id;
        $descendants = collect($this->descendants)->pluck('id')->toArray();
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

    public static function nonReportableIds(): array
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
            //dump($cat->id . ' - ' . $cat->name . ' -> ' . $cat->parent->id . ' - ' . $cat->parent->name);
            $cat = $cat->parent;
        }

        //dump($this->name . ' (' . $this->id . ') ==> ' . $cat->id);
        return new Attribute(
            get: fn () => $cat,
        );
    }
}
