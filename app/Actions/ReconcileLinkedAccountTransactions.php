<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;
use Carbon\Carbon;

final class ReconcileLinkedAccountTransactions
{
    public static function run(LinkedAccount $linkedAccount, bool $force = false): void
    {
        /* echo("ID\tNAME\tTYPE\tAMOUNT\tRUNNING\tNEXT\n"); */
        foreach ($linkedAccount->accounts as $account) {
            $balance = $account->current_balance;
            $transactions = $account
                ->transactions()
                ->orderByRaw('created_at desc, (amount > 0) asc, id asc')
                ->get();

            $last_day = $transactions->first()->created_at->copy();
            foreach ($transactions as $transaction) {
                /* if (!$transaction->created_at->isSameDay($last_day)) { */
                /*     echo "=====================\n"; */
                /*     $last_day = $transaction->created_at->copy(); */
                /* } */

                if (!$force && $transaction->running_balance === $balance) {
                    break;
                }
                $transaction->running_balance = $balance;
                $transaction->save();

                $balance -= $transaction->amount;
                $balance = round($balance, 2);

                $name = $transaction->name;
                $name = substr($name, 0, 30);
                //echo("$transaction->created_at\t$name\t$transaction->transaction_type\t$transaction->amount\t$transaction->running_balance\t(next balance: $balance)\n");
            }
        }
    }
}
