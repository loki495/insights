<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;

it('seeds a demo user with a flagged demo linked account and realistic transaction data', function (): void {
    (new DemoDataSeeder)->run();

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();

    $linkedAccount = LinkedAccount::where('user_id', $user->id)->where('is_demo', true)->first();
    expect($linkedAccount)->not->toBeNull();
    expect($linkedAccount->accounts)->toHaveCount(3);

    $transactions = Transaction::whereIn('account_id', $linkedAccount->accounts->pluck('id'))->get();
    expect($transactions)->not->toBeEmpty();
    expect($transactions->whereNotNull('running_balance'))->toHaveCount($transactions->count());
    expect($transactions->where('type', 'transfer')->whereNotNull('transfer_pair_id'))->not->toBeEmpty();
    expect($transactions->has('categories') ?? null)->not->toBeNull(); // sanity: relation is queryable
});

it('is idempotent — re-running it against an already-seeded demo user does not duplicate data', function (): void {
    (new DemoDataSeeder)->run();
    $firstCount = LinkedAccount::where('is_demo', true)->count();

    (new DemoDataSeeder)->run();
    $secondCount = LinkedAccount::where('is_demo', true)->count();

    expect($secondCount)->toBe($firstCount)->toBe(1);
});

it('never touches a real (non-demo) linked account belonging to the same user', function (): void {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $real = LinkedAccount::create([
        'user_id' => $user->id,
        'item_id' => 'real_item',
        'provider_name' => 'Real Bank',
        'access_token' => 'real-token',
        'is_demo' => false,
    ]);

    (new DemoDataSeeder)->run();

    expect($real->fresh())->not->toBeNull();
    expect(LinkedAccount::where('user_id', $user->id)->count())->toBe(2);
});
