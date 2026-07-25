<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\User;

final class CreateOrAdoptCategoryAction
{
    /**
     * $description is null by default because the quick-picker "create category" flow
     * (HasCategoryAssignment::createCategory()) has no description field at all — null there
     * means "no opinion", not "clear it", so it's only applied when explicitly passed (e.g. by
     * the admin category form, which always has a real, if possibly empty, description value).
     */
    public static function run(User $user, ?int $parentId, string $name, ?string $color, ?string $description = null): Category
    {
        $category = FindOrCreateCategoryAction::run($parentId, $name);

        if ($description !== null && $category->description !== $description) {
            $category->update(['description' => $description]);
        }

        AdoptCategoryForUserAction::run($user, $category, $color);

        return $category;
    }
}
