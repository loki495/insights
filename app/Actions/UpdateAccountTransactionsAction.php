<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;
use App\Models\OriginalCategory;
use App\Models\Transaction;

final class UpdateAccountTransactionsAction
{
    /**
     * @param  array<string, mixed>  $transaction_info
     */
    public static function run(array $transaction_info, string $action, LinkedAccount $linkedAccount): void
    {
        if ($action === 'removed') {
            Transaction::where('transaction_id', $transaction_info['transaction_id'])
                ->whereHas('account', fn ($query) => $query->where('linked_account_id', $linkedAccount->id))
                ->delete();

            return;
        }

        $transaction_usable = self::getUsableTransaction($transaction_info, $linkedAccount);

        $category = plaid()->resolveCategory($transaction_info);

        if ($category instanceof OriginalCategory) {
            $transaction_usable['original_category_id'] = $category->id;
        }

        $transaction = Transaction::query()
            ->whereHas('account', fn ($query) => $query->where('linked_account_id', $linkedAccount->id))
            ->updateOrCreate(
                ['transaction_id' => $transaction_info['transaction_id']],
                $transaction_usable
            );

        $transaction->refreshType();

        if ($transaction->categories->isEmpty()) {
            ApplyCategoryRulesAction::run($transaction);
        }
    }

    /**
     * @param  array<string, mixed>  $transaction_info
     * @return array<string, mixed>
     */
    private static function getUsableTransaction(array $transaction_info, LinkedAccount $linkedAccount): array
    {
        $account = Account::query()->where('linked_account_id', $linkedAccount->id)->where('plaid_account_id', $transaction_info['account_id'])->first();
        if (! $account) {
            throw new \Exception('Account not found - '.$transaction_info['account_id']);
        }
        $account_id = $account->id;

        // adjust amount sign so that Debit (money OUT) is negative and Credit (money IN) is positive
        $amount = -1 * $transaction_info['amount'];
        $type = 'Credit';
        if ($amount < 0) {
            $type = 'Debit';
        }

        return [
            'account_id' => $account_id,
            'amount' => $amount,
            'authorized_at' => $transaction_info['authorized_datetime'] ?? $transaction_info['authorized_date'],
            'created_at' => $transaction_info['datetime'] ?? $transaction_info['date'],
            'currency' => $transaction_info['iso_currency_code'],
            'logo_url' => $transaction_info['logo_url'],
            'merchant_name' => cleanPlaidText($transaction_info['merchant_name']),
            'merchant_entity_id' => $transaction_info['merchant_entity_id'],
            'name' => cleanPlaidText($transaction_info['name']),
            'payment_channel' => $transaction_info['payment_channel'],
            'transaction_id' => $transaction_info['transaction_id'],
            'transaction_type' => $type,
            'website' => $transaction_info['website'],
            'original' => $transaction_info,
        ];
    }
}
