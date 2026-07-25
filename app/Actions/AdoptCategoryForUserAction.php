<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\User;

final class AdoptCategoryForUserAction
{
    /**
     * syncWithoutDetaching() updates the pivot color even when already attached, so this single
     * call correctly covers both first-adoption and re-coloring an already-adopted category.
     */
    public static function run(User $user, Category $category, ?string $color): void
    {
        $user->categories()->syncWithoutDetaching([
            $category->id => ['color' => $color ?: '#3b82f6'],
        ]);
    }
}
