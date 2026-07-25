<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

final class FindOrCreateCategoryAction
{
    /**
     * Categories are a shared, deduplicated (parent_id, name) vocabulary — never duplicated per
     * user. Matches case-insensitively so "Coffee" and "coffee" under the same parent resolve to
     * the same row. lockForUpdate() is inert on SQLite (this app's only tested driver today) but
     * closes the race on a real concurrent-write database if this ever runs on one.
     */
    public static function run(?int $parentId, string $name): Category
    {
        $parentId = $parentId ?: 0;
        $name = trim($name);

        return DB::transaction(function () use ($parentId, $name): Category {
            $existing = Category::query()
                ->where('parent_id', $parentId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return Category::create([
                'parent_id' => $parentId,
                'name' => $name,
            ]);
        });
    }
}
