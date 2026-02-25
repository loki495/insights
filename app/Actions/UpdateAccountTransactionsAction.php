<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\OriginalCategory;
use App\Models\Transaction;

final class UpdateAccountTransactionsAction
{
    public static function run(array $transaction_info, $action): void
    {
        $transaction_usable = self::getUsableTransaction($transaction_info);

        if ($action === 'deleted') {
            Transaction::where('transaction_id', $transaction_info['transaction_id'])->delete();

            return;
        }

        $category = OriginalCategory::updateOrCreate(
            ['plaid_id' => $transaction_info['category_id']],
            [
                'name' => $transaction_info['category'][0] ?? 'Other',
                'description' => $transaction_info['personal_finance_category']['detailed'] ?? 'Other',
                'logo_url' => $transaction_info['personal_finance_category_icon_url'],
            ],
        );

        $transaction_usable['original_category_id'] = $category->id;

        $transaction = Transaction::updateOrCreate(
            ['transaction_id' => $transaction_info['transaction_id']],
            $transaction_usable
        );

        $transaction->original_category_id = $category->id;
        $transaction->save();

    }

    private static function getUsableTransaction(array $transaction_info): array
    {
        $account_id = Account::query()->where('plaid_account_id', $transaction_info['account_id'])->first()->id;
        if (! $account_id) {
            throw new \Exception('Account not found - ' . $transaction_info['account_id']);
        }

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
            'merchant_name' => $transaction_info['merchant_name'],
            'merchant_entity_id' => $transaction_info['merchant_entity_id'],
            'name' => $transaction_info['name'],
            'payment_channel' => $transaction_info['payment_channel'],
            'transaction_id' => $transaction_info['transaction_id'],
            'transaction_type' => $type,
            'website' => $transaction_info['website'],
            'original' => $transaction_info,
        ];
    }
}
