<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores and reads back a production-length Plaid access token', function (): void {
    $token = 'access-production-'.fake()->uuid();
    expect($token)->toHaveLength(54);

    $linkedAccount = LinkedAccount::factory()->for(User::factory()->create())->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => $token,
    ]);

    $raw = (string) DB::table('linked_accounts')->where('id', $linkedAccount->id)->value('access_token');

    // The encrypted payload is what must fit the column: strict MySQL rejects it past 255 characters.
    expect(strlen($raw))->toBeGreaterThan(255)
        ->and($linkedAccount->fresh()->access_token)->toBe($token);
});
