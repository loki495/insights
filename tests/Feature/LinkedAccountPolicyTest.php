<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\User;

it('lets a user view/update/delete their own linked account', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    expect($user->can('view', $linkedAccount))->toBeTrue()
        ->and($user->can('update', $linkedAccount))->toBeTrue()
        ->and($user->can('delete', $linkedAccount))->toBeTrue();
});

it('prevents a user from viewing/updating/deleting another user\'s linked account', function (): void {
    $owner = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($owner)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    $stranger = User::factory()->create();

    expect($stranger->can('view', $linkedAccount))->toBeFalse()
        ->and($stranger->can('update', $linkedAccount))->toBeFalse()
        ->and($stranger->can('delete', $linkedAccount))->toBeFalse();
});

it('lets any authenticated user create or view-any linked accounts', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', LinkedAccount::class))->toBeTrue()
        ->and($user->can('viewAny', LinkedAccount::class))->toBeTrue();
});
