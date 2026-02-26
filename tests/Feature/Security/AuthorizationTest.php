<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\LinkedAccount;
use App\Models\Account;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('user cannot view another users linked account index', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $linkedAccount2 = LinkedAccount::factory()->for($user2)->create([
        'item_id' => 'item_2',
        'access_token' => 'access_2',
    ]);

    $this->actingAs($user1);

    $this->get(route('linked-accounts.accounts.index', $linkedAccount2))
        ->assertForbidden();
});

test('user cannot view another users account show page', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $linkedAccount2 = LinkedAccount::factory()->for($user2)->create([
        'item_id' => 'item_2',
        'access_token' => 'access_2',
    ]);
    $account2 = Account::factory()->for($linkedAccount2, 'linked_account')->create([
        'plaid_account_id' => 'acc_2',
        'name' => 'Secret Account',
        'official_name' => 'Secret Account Official',
        'mask' => '1234',
        'type' => 'depository',
        'subtype' => 'checking',
        'currency' => 'USD',
    ]);

    $this->actingAs($user1);

    $this->get(route('linked-accounts.accounts.show', [$linkedAccount2, $account2]))
        ->assertForbidden();
});

test('user can view their own account', function () {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_1',
        'access_token' => 'access_1',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'acc_1',
        'name' => 'My Account',
        'official_name' => 'My Account Official',
        'mask' => '1234',
        'type' => 'depository',
        'subtype' => 'checking',
        'currency' => 'USD',
    ]);

    $this->actingAs($user);

    $this->get(route('linked-accounts.accounts.show', [$linkedAccount, $account]))
        ->assertOk();
});
