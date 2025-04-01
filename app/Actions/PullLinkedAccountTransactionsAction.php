<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;

final class PullLinkedAccountTransactionsAction
{
    public static function run(LinkedAccount $linkedAccount): void
    {
        Account::truncate();
        Transaction::truncate();

        $plaid = plaid();
        $result = $plaid->getItemTransactions(data: [
            'access_token' => $linkedAccount->access_token,
        ]);

        $types = [
            'added',
            'removed',
            'modified',
        ];

        foreach ($result['accounts'] ?? [] as $account) {
            UpdateAccountAction::run($account, $linkedAccount);
        }

        foreach ($types as $type) {
            foreach ($result[$type] ?? [] as $transaction) {
                UpdateAccountTransactionsAction::run($transaction, $type);
            }
        }

        ReconcileLinkedAccountTransactions::run($linkedAccount);
    }
}
