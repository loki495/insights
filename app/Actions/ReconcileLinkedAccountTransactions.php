<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;

final class ReconcileLinkedAccountTransactions
{
    public static function run(LinkedAccount $linkedAccount, bool $force = false): void
    {
        foreach ($linkedAccount->accounts as $account) {
            $balance = $account->current_balance;
            $transactions = $account
                ->transactions()
                ->orderByRaw('created_at desc, (amount > 0) asc, id asc')
                ->get();

            if ($transactions->isEmpty()) {
                continue;
            }

            foreach ($transactions as $transaction) {
                if (! $force && $transaction->running_balance === $balance) {
                    break;
                }
                $transaction->running_balance = $balance;
                $transaction->save();

                $balance -= $transaction->amount;
                $balance = round($balance, 2);
            }
        }
    }
}
