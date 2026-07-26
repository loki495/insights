<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\User;
use Livewire\Livewire;

it('links the institution name to its accounts page', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'provider_name' => 'Chase Bank',
    ]);

    $this->actingAs($user);

    $test = Livewire::test('admin.linked-accounts.index');

    $test->assertSeeHtml('href="'.route('linked-accounts.accounts.index', $linkedAccount->id).'"');
    $test->assertSeeHtml('Chase Bank');
});
