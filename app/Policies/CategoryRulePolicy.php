<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CategoryRule;
use App\Models\User;

class CategoryRulePolicy
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
     * Unlike Category (a shared vocabulary), a rule is fully private to whoever created it.
     */
    public function view(User $user, CategoryRule $categoryRule): bool
    {
        return $user->id === $categoryRule->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CategoryRule $categoryRule): bool
    {
        return $this->view($user, $categoryRule);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CategoryRule $categoryRule): bool
    {
        return $this->view($user, $categoryRule);
    }
}
