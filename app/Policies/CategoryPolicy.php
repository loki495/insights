<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     *
     * Categories are a shared, deduplicated vocabulary (see app/Actions/FindOrCreateCategoryAction.php)
     * — "view" here means "has this user actually adopted it" (app/Models/User.php::categories()),
     * not "does it exist". No existing call site authorizes a single Category with this yet; the
     * adopted-only scoping users actually see is enforced by query-scoping in
     * HasCategoryAssignment and the admin categories views. Implemented correctly here anyway as
     * defense-in-depth for any future call site.
     */
    public function view(User $user, Category $category): bool
    {
        return $user->categories()->where('categories.id', $category->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     *
     * Anyone can create/reuse a category — they're a shared, freely discoverable vocabulary.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * "Update" never mutates the shared row (see app/Actions/EditUserCategoryAction.php) — this
     * just gates who may fork/re-adopt it, same rule as view().
     */
    public function update(User $user, Category $category): bool
    {
        return $this->view($user, $category);
    }

    /**
     * Determine whether the user can delete the model.
     *
     * "Delete" only ever removes the acting user's own adoption/usage (see
     * app/Actions/RemoveCategoryForUserAction.php) — the shared row is never deleted, same rule
     * as view().
     */
    public function delete(User $user, Category $category): bool
    {
        return $this->view($user, $category);
    }
}
