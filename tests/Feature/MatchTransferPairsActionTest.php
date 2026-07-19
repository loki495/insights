<?php

declare(strict_types=1);

use App\Actions\MatchTransferPairsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeMatchTestAccount(array $overrides = []): Account
{
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create(array_merge([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000',
        'name' => 'Account',
        'official_name' => 'Account Official',
        'type' => 'depository',
        'subtype' => 'checking',
        'currency' => 'USD',
    ], $overrides));
}

it('pairs two opposite-sign transfer transactions across different accounts', function (): void {
    $checking = makeMatchTestAccount(['name' => 'Checking']);
    $card = makeMatchTestAccount(['name' => 'Card', 'type' => 'credit', 'subtype' => 'credit card']);

    $out = Transaction::factory()->for($checking)->create([
        'name' => 'Card payment', 'amount' => -200, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $in = Transaction::factory()->for($card)->create([
        'name' => 'Payment received', 'amount' => 200, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(1);
    expect($out->fresh()->transfer_pair_id)->toBe($in->id);
    expect($in->fresh()->transfer_pair_id)->toBe($out->id);
});

it('never pairs two transactions from the same account, protecting refunds from being mistaken for transfers', function (): void {
    $account = makeMatchTestAccount();

    $a = Transaction::factory()->for($account)->create([
        'name' => 'Leg A', 'amount' => -75, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $b = Transaction::factory()->for($account)->create([
        'name' => 'Leg B', 'amount' => 75, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(0);
    expect($a->fresh()->transfer_pair_id)->toBeNull();
    expect($b->fresh()->transfer_pair_id)->toBeNull();
});

it('tolerates a small FX/fee spread between the two legs of a cross-currency transfer', function (): void {
    $usd = makeMatchTestAccount(['name' => 'USD Checking', 'currency' => 'USD']);
    $cad = makeMatchTestAccount(['name' => 'CAD Savings', 'currency' => 'CAD']);

    $out = Transaction::factory()->for($usd)->create([
        'name' => 'To CAD account', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $in = Transaction::factory()->for($cad)->create([
        'name' => 'From USD account', 'amount' => 101, 'currency' => 'CAD', 'type' => 'transfer', // 1% spread
        'authorized_at' => '2026-06-11', 'created_at' => '2026-06-11',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(1);
    expect($out->fresh()->transfer_pair_id)->toBe($in->id);
});

it('does not pair legs whose amounts differ by more than the tolerance', function (): void {
    $usd = makeMatchTestAccount(['name' => 'USD Checking']);
    $other = makeMatchTestAccount(['name' => 'Other']);

    $out = Transaction::factory()->for($usd)->create([
        'name' => 'Out', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $in = Transaction::factory()->for($other)->create([
        'name' => 'In', 'amount' => 110, 'currency' => 'USD', 'type' => 'transfer', // 10% off, outside tolerance
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(0);
    expect($out->fresh()->transfer_pair_id)->toBeNull();
    expect($in->fresh()->transfer_pair_id)->toBeNull();
});

it('does not pair legs outside the date window', function (): void {
    $a = makeMatchTestAccount(['name' => 'A']);
    $b = makeMatchTestAccount(['name' => 'B']);

    $out = Transaction::factory()->for($a)->create([
        'name' => 'Out', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-01', 'created_at' => '2026-06-01',
    ]);
    $in = Transaction::factory()->for($b)->create([
        'name' => 'In', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10', // 9 days later
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(0);
    expect($out->fresh()->transfer_pair_id)->toBeNull();
    expect($in->fresh()->transfer_pair_id)->toBeNull();
});

it('flags but does not auto-match pairs spanning an investment or loan account', function (): void {
    $checking = makeMatchTestAccount(['name' => 'Checking']);
    $brokerage = makeMatchTestAccount(['name' => 'Brokerage', 'type' => 'investment', 'subtype' => 'brokerage']);

    $out = Transaction::factory()->for($checking)->create([
        'name' => 'To brokerage', 'amount' => -500, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $in = Transaction::factory()->for($brokerage)->create([
        'name' => 'Deposit', 'amount' => 500, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(0);
    expect($result['flagged_investment_or_loan'])->toBe(1);
    expect($out->fresh()->transfer_pair_id)->toBeNull();
    expect($in->fresh()->transfer_pair_id)->toBeNull();
});

it('leaves an already-paired transaction untouched and only pairs the remaining unpaired ones', function (): void {
    $a = makeMatchTestAccount(['name' => 'A']);
    $b = makeMatchTestAccount(['name' => 'B']);
    $c = makeMatchTestAccount(['name' => 'C']);
    $d = makeMatchTestAccount(['name' => 'D']);

    $existingPairA = Transaction::factory()->for($a)->create([
        'name' => 'Already paired A', 'amount' => -50, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $existingPairB = Transaction::factory()->for($b)->create([
        'name' => 'Already paired B', 'amount' => 50, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-10', 'created_at' => '2026-06-10',
    ]);
    $existingPairA->update(['transfer_pair_id' => $existingPairB->id]);
    $existingPairB->update(['transfer_pair_id' => $existingPairA->id]);

    $newOut = Transaction::factory()->for($c)->create([
        'name' => 'New out', 'amount' => -75, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-11', 'created_at' => '2026-06-11',
    ]);
    $newIn = Transaction::factory()->for($d)->create([
        'name' => 'New in', 'amount' => 75, 'currency' => 'USD', 'type' => 'transfer',
        'authorized_at' => '2026-06-11', 'created_at' => '2026-06-11',
    ]);

    $result = MatchTransferPairsAction::run();

    expect($result['matched_pairs'])->toBe(1);
    expect($existingPairA->fresh()->transfer_pair_id)->toBe($existingPairB->id);
    expect($existingPairB->fresh()->transfer_pair_id)->toBe($existingPairA->id);
    expect($newOut->fresh()->transfer_pair_id)->toBe($newIn->id);
});
