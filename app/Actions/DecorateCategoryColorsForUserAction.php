<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DecorateCategoryColorsForUserAction
{
    /**
     * Category carries no color of its own anymore — it lives on the per-user category_user
     * pivot. This bulk-fetches the acting user's colors for the given categories in one query and
     * sets them back onto the model instances in place (so existing `$category->color` reads
     * throughout the app keep working unchanged). Categories the user hasn't adopted (e.g. a
     * shared-tree parent shown only for its name) fall back to the same default used everywhere
     * else in this app.
     *
     * @param  iterable<Category>  $categories
     */
    public static function run(User $user, iterable $categories): void
    {
        $categories = collect($categories)->filter();

        $categoryIds = $categories->pluck('id')->unique();

        if ($categoryIds->isEmpty()) {
            return;
        }

        $colors = DB::table('category_user')
            ->where('user_id', $user->id)
            ->whereIn('category_id', $categoryIds)
            ->pluck('color', 'category_id');

        foreach ($categories as $category) {
            $category->setAttribute('color', $colors[$category->id] ?? '#3b82f6');
        }
    }
}
