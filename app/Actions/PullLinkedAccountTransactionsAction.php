<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use App\Services\Plaid\PlaidService;

final class PullLinkedAccountTransactionsAction
{
    static public function run(LinkedAccount $linkedAccount)
    {
        Account::truncate();
        Transaction::truncate();

        $plaid = plaid();
        $result = $plaid->getItemTransactions(data: [
            'access_token' => $linkedAccount->access_token
        ]);

        $has_more = $result['has_more'] ?? false;

        $types = [
            'added',
            'removed',
            'modified',
        ];

        $transactions = [];

        foreach ($result['accounts'] as $account) {
            UpdateAccountAction::run($account, $linkedAccount);
        }

        foreach ($types as $type) {
            foreach ($result[$type] as $transaction) {
                UpdateAccountTransactionsAction::run($transaction, $type);
            }
        }

        ReconcileLinkedAccountTransactions::run($linkedAccount);
    }
}
