<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;

function accountFor(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Checking', 'official_name' => 'Checking Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
}

it('lets a user view/update/delete their own account', function (): void {
    $user = User::factory()->create();
    $account = accountFor($user);

    expect($user->can('view', $account))->toBeTrue()
        ->and($user->can('update', $account))->toBeTrue()
        ->and($user->can('delete', $account))->toBeTrue();
});

it('prevents a user from viewing/updating/deleting another user\'s account', function (): void {
    $owner = User::factory()->create();
    $account = accountFor($owner);

    $stranger = User::factory()->create();

    expect($stranger->can('view', $account))->toBeFalse()
        ->and($stranger->can('update', $account))->toBeFalse()
        ->and($stranger->can('delete', $account))->toBeFalse();
});

it('lets any authenticated user create or view-any accounts', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', Account::class))->toBeTrue()
        ->and($user->can('viewAny', Account::class))->toBeTrue();
});
