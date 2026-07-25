<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;

/**
 * The actual regression test for the CRITICAL pre-launch audit finding: CategoryPolicy
 * unconditionally returned true for view/update/delete on any category, so any self-registered
 * stranger could rename or delete another user's categories. It's now scoped to adoption via the
 * category_user pivot (see app/Models/User.php::categories()).
 */
it('lets a user view/update/delete a category they have adopted', function (): void {
    $user = User::factory()->create();
    $category = categoryFor($user, 'Groceries');

    expect($user->can('view', $category))->toBeTrue();
    expect($user->can('update', $category))->toBeTrue();
    expect($user->can('delete', $category))->toBeTrue();
});

it('prevents a user from viewing/updating/deleting a category adopted only by another user', function (): void {
    $owner = User::factory()->create();
    $category = categoryFor($owner, 'Groceries');

    $stranger = User::factory()->create();

    expect($stranger->can('view', $category))->toBeFalse();
    expect($stranger->can('update', $category))->toBeFalse();
    expect($stranger->can('delete', $category))->toBeFalse();
});

it('lets any authenticated user create or view-any categories, since they are a shared vocabulary', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', Category::class))->toBeTrue();
    expect($user->can('viewAny', Category::class))->toBeTrue();
});
