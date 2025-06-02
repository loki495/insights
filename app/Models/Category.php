<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    public function parent(): HasOne
    {
        return $this->hasOne(Category::class, 'id', 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id', 'id');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class)->withPivot('id');
    }

    public function descendants() : Attribute
    {
        $descendants = [];

        // cache children
        $children = Category::where('parent_id', $this->id)->get();

        foreach ($children as $child) {
            $descendants[] = $child;
            $descendants = array_merge($descendants, $child->descendants);
        }

        return new Attribute(
            get: fn ($value) => $descendants,
        );
    }

    public function descendantTransactions(): Attribute
    {
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
}
