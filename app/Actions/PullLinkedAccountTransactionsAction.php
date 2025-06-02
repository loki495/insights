<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\Transaction;

final class PullLinkedAccountTransactionsAction
{
    public static function run(LinkedAccount $linkedAccount, ?string $cursor = null): void
    {
        $plaid = plaid();

        $request_data = [
            'access_token' => $linkedAccount->access_token,
            'count' => 500,
            'options' => [
                'days_requested' => 730,
            ],
        ];

        if ($cursor) {
            $request_data['cursor'] = $cursor;
        }

        $result = $plaid->getItemTransactions(data: $request_data);

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

        if ($result['has_more'] ?? false) {
            self::run($linkedAccount, $result['next_cursor']);
        } else {
            ReconcileLinkedAccountTransactions::run($linkedAccount);
        }
    }
}
