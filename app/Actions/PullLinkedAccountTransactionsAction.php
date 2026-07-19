<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;

final class PullLinkedAccountTransactionsAction
{
    public static function run(LinkedAccount $linkedAccount, ?string $cursor = null, bool $force = false): void
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

        $use_cache = false;
        $fn = Storage::disk('local')->path('plaid/transactions/'.$linkedAccount->id.'.json');
        @mkdir(dirname($fn), 0777, true);
        if ($use_cache && file_exists($fn)) {
            $result = json_decode((string) file_get_contents($fn), true);
        } else {
            $result = $plaid->getItemTransactions(data: $request_data);
            file_put_contents($fn, json_encode($result));
        }

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
        }
    }
}
