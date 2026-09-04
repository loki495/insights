<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\User;

test('guests are redirected to the login page', function (): void {
    $response = $this->get('/');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard and see accounts', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'provider_name' => 'Test Bank',
        'item_id' => 'item_123',
        'access_token' => 'access_123',
    ]);
    $account = Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'account_123',
        'mask' => '1234',
        'name' => 'Checking Account',
        'official_name' => 'Test Bank Checking',
        'type' => 'depository',
        'subtype' => 'checking',
        'current_balance' => 1234.56,
        'currency' => 'USD',
    ]);

    $this->actingAs($user);

    $response = $this->get('/');
    $response->assertStatus(200);
    $response->assertSee('Test Bank');
    $response->assertSee('Checking Account');
    $response->assertSee('1,234.56');
});

test('disabled accounts are omitted from the dashboard', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'provider_name' => 'Test Bank',
        'item_id' => 'item_123',
        'access_token' => 'access_123',
    ]);
    Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'disabled_account',
        'disabled_at' => now(),
        'mask' => '1234',
        'name' => 'Closed Checking Account',
        'official_name' => 'Closed Checking Account',
        'type' => 'depository',
        'subtype' => 'checking',
        'current_balance' => 1234.56,
        'currency' => 'USD',
    ]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Closed Checking Account')
        ->assertDontSeeText('1,234.56');
});
