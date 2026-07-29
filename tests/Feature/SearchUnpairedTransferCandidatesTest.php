<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Models\User;

function makeTransferCandidateAccount(User $user): Account
{
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(), 'access_token' => 'access_'.uniqid(),
    ]);

    return Account::factory()->for($linkedAccount, 'linked_account')->create([
        'plaid_account_id' => 'plaid_'.uniqid(),
        'mask' => '0000', 'name' => 'Account', 'official_name' => 'Account Official',
        'type' => 'depository', 'subtype' => 'checking',
    ]);
}

it('returns an empty collection for a blank search term instead of every unpaired transfer', function (): void {
    $user = User::factory()->create();
    $account = makeTransferCandidateAccount($user);
    $other = makeTransferCandidateAccount($user);
    Transaction::factory()->for($other)->create([
        'name' => 'Card Payment', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);

    $results = Transaction::searchUnpairedTransferCandidates(0, $account->id, '   ');

    expect($results)->toBeEmpty();
});

it('matches a candidate by name or merchant_name, excluding the given transaction/account and non-matches', function (): void {
    $user = User::factory()->create();
    $account = makeTransferCandidateAccount($user);
    $other = makeTransferCandidateAccount($user);

    $current = Transaction::factory()->for($account)->create([
        'name' => 'Card Payment', 'amount' => -100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $byName = Transaction::factory()->for($other)->create([
        'name' => 'Card Payment', 'merchant_name' => null, 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $byMerchant = Transaction::factory()->for($other)->create([
        'name' => 'Payment', 'merchant_name' => 'Card Payment Co', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $sameAccount = Transaction::factory()->for($account)->create([
        'name' => 'Card Payment', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
    ]);
    $notATransfer = Transaction::factory()->for($other)->create([
        'name' => 'Card Payment', 'amount' => 100, 'currency' => 'USD', 'type' => 'expense',
    ]);
    $alreadyPaired = Transaction::factory()->for($other)->create([
        'name' => 'Card Payment', 'amount' => 100, 'currency' => 'USD', 'type' => 'transfer',
        'transfer_pair_id' => $current->id,
    ]);

    $results = Transaction::searchUnpairedTransferCandidates($current->id, $account->id, 'Card Payment');

    expect($results->pluck('id'))->toContain($byName->id)
        ->toContain($byMerchant->id)->not->toContain($current->id)->not->toContain($sameAccount->id)->not->toContain($notATransfer->id)->not->toContain($alreadyPaired->id);
});
