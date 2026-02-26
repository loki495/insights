<?php

declare(strict_types=1);

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests are redirected to the login page', function (): void {
    $response = $this->get('/');
    $response->assertRedirect('/login');
});

test('authenticated users can visit the dashboard and see accounts', function (): void {
    $user = User::factory()->create();
    $linkedAccount = \App\Models\LinkedAccount::factory()->for($user)->create([
        'provider_name' => 'Test Bank',
        'item_id' => 'item_123',
        'access_token' => 'access_123',
    ]);
    $account = \App\Models\Account::factory()->for($linkedAccount, 'linked_account')->create([
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
