<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;

final class ReconcileLinkedAccountTransactions
{
    public static function run(LinkedAccount $linkedAccount): void
    {
        foreach ($linkedAccount->accounts as $account) {
            $balance = $account->current_balance;
            foreach ($account->transactions()->orderBy('id')->get() as $transaction) {
                $transaction->running_balance = $balance;
                $transaction->save();
                $balance -= $transaction->amount;
            }
        }
    }
}
