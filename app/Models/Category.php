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

    public function fullName() : Attribute
    {
        // CACHE
        return new Attribute(
            get: fn ($value) => $this->parent_id ? Category::find($this->parent_id)->fullName . ' > ' . $this->name : $this->name,
        );
    }

    public function descendants() : Attribute
    {
        $descendants = [ $this->id ];

        // cache children
        $children = Category::where('parent_id', $this->id)->get();

        foreach ($children as $child) {
            //$descendants[] = $child;
            $child_descendants = $child->descendants;

            $descendants = array_merge($descendants, $child_descendants);
        }

        // CACHE
        return new Attribute(
            get: fn ($value) => $descendants,
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
        return Category::where('name', 'Transfers')
            ->first()
            ->descendants;
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
