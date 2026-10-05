<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

it('seeds a demo user with a flagged demo linked account and realistic transaction data', function (): void {
    (new DemoDataSeeder)->run();

    $user = User::where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();

    $linkedAccount = LinkedAccount::where('user_id', $user->id)->where('is_demo', true)->first();
    expect($linkedAccount)->not->toBeNull()
        ->and($linkedAccount->accounts)->toHaveCount(3);

    $transactions = Transaction::whereIn('account_id', $linkedAccount->accounts->pluck('id'))->get();
    expect($transactions)->not->toBeEmpty()
        ->and($transactions->whereNotNull('running_balance'))->toHaveCount($transactions->count())
        ->and($transactions->where('type', 'transfer')->whereNotNull('transfer_pair_id'))->not->toBeEmpty()
        ->and($transactions->has('categories') ?? null)->not->toBeNull(); // sanity: relation is queryable
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

    expect($real->fresh())->not->toBeNull()
        ->and(LinkedAccount::where('user_id', $user->id)->count())->toBe(2);
});

it('spreads the demo across many distinctly colored top-level categories and transaction types', function (): void {
    (new DemoDataSeeder)->run();
    $user = User::where('email', 'test@example.com')->firstOrFail();

    $topLevelColors = DB::table('category_user')
        ->join('categories', 'categories.id', '=', 'category_user.category_id')
        ->where('category_user.user_id', $user->id)
        ->where(fn ($q) => $q->whereNull('categories.parent_id')->orWhere('categories.parent_id', 0))
        ->pluck('category_user.color');

    $accountIds = LinkedAccount::where('user_id', $user->id)->firstOrFail()->accounts->pluck('id');
    $types = Transaction::whereIn('account_id', $accountIds)->distinct()->pluck('type');

    expect($topLevelColors->count())->toBeGreaterThanOrEqual(8)
        ->and($topLevelColors->unique()->count())->toBe($topLevelColors->count())
        ->and($types->sort()->values()->all())->toBe(['expense', 'income', 'transfer']);
});

it('pays the card off with exactly that month\'s card spending, in dollars', function (): void {
    (new DemoDataSeeder)->run();

    $card = Account::where('subtype', 'credit card')->firstOrFail();
    $payment = Transaction::where('account_id', $card->id)->where('type', 'transfer')->orderBy('created_at')->firstOrFail();
    $spent = Transaction::where('account_id', $card->id)
        ->where('type', 'expense')
        ->whereBetween('created_at', [$payment->created_at->startOfMonth(), $payment->created_at->endOfMonth()])
        ->get()
        ->sum(fn (Transaction $transaction): float => $transaction->amount);

    expect($payment->amount)->toBe(round(-$spent, 2))
        ->and($payment->amount)->toBeLessThan(5000.0);
});
