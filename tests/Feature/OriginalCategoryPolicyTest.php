<?php

declare(strict_types=1);

use App\Models\OriginalCategory;
use App\Models\User;

/**
 * OriginalCategory is Plaid's own shared taxonomy, not user data — every authenticated user can
 * view it, but nobody (not even the owner of a transaction referencing it) can create/update/
 * delete it. Locking that in so a future edit doesn't accidentally make it mutable.
 */
it('lets any authenticated user view OriginalCategory records', function (): void {
    $user = User::factory()->create();
    $category = OriginalCategory::create(['name' => 'Restaurants', 'plaid_id' => '13005000']);

    expect($user->can('viewAny', OriginalCategory::class))->toBeTrue()
        ->and($user->can('view', $category))->toBeTrue();
});

it('never lets any user create/update/delete an OriginalCategory record', function (): void {
    $user = User::factory()->create();
    $category = OriginalCategory::create(['name' => 'Restaurants', 'plaid_id' => '13005000']);

    expect($user->can('create', OriginalCategory::class))->toBeFalse()
        ->and($user->can('update', $category))->toBeFalse()
        ->and($user->can('delete', $category))->toBeFalse();
});
