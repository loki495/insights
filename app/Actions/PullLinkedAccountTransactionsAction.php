<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;
use App\Models\Transaction;

final class PullLinkedAccountTransactionsAction
{
    public static function run(LinkedAccount $linkedAccount, ?string $cursor = null, bool $force = false): void
    {
        // Only the outermost call (as opposed to a paginated continuation) should record
        // success/failure — a later page failing shouldn't erase an earlier page's success, and
        // "succeeded" should only mean the whole pull (all pages) finished.
        if ($cursor === null) {
            try {
                self::pull($linkedAccount, null, $force);
                $linkedAccount->update(['last_sync_failed_at' => null, 'last_sync_error' => null]);
            } catch (\Throwable $e) {
                $linkedAccount->update(['last_sync_failed_at' => now(), 'last_sync_error' => $e->getMessage()]);
                throw $e;
            }

            return;
        }

        self::pull($linkedAccount, $cursor, $force);
    }

    private static function pull(LinkedAccount $linkedAccount, ?string $cursor, bool $force): void
    {
        if ($linkedAccount->isClosed() || $linkedAccount->is_demo) {
            return;
        }

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

        if ($result['has_more']) {
            self::pull($linkedAccount, $result['next_cursor'], $force);
        } else {
            ReconcileLinkedAccountTransactions::run($linkedAccount, $force);

            // Transfers commonly span different institutions (e.g. a Chase checking payment to a
            // Capital One card), so this matches across all of the user's linked accounts, not just
            // this one — scoped by user_id so different users' transactions can never be paired.
            MatchTransferPairsAction::run(
                Transaction::query()->whereHas(
                    'account.linked_account',
                    fn ($query) => $query->where('user_id', $linkedAccount->user_id)
                )
            );

            $linkedAccount->update(['last_pulled_at' => now()]);
        }
    }
}
