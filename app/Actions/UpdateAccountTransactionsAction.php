<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\OriginalCategory;
use App\Models\Transaction;

final class UpdateAccountTransactionsAction
{
    static public function run($transaction_info, $action)
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


    }

    static private function getUsableTransaction($transaction_info)
    {
        $transaction = [
            'account_id' => Account::where('plaid_id', $transaction_info['account_id'])->first()->id,
            'amount' => $transaction_info['amount'],
            'authorized_at' => $transaction_info['authorized_datetime'] ?? $transaction_info['authorized_date'],
            'created_at' => $transaction_info['datetime'] ?? $transaction_info['date'],
            'currency' => $transaction_info['iso_currency_code'],
            'logo_url' => $transaction_info['logo_url'],
            'merchant_name' => $transaction_info['merchant_name'],
            'merchant_entity_id' => $transaction_info['merchant_entity_id'],
            'name' => $transaction_info['name'],
            'payment_channel' => $transaction_info['payment_channel'],
            'transaction_id' => $transaction_info['transaction_id'],
            'transaction_type' => $transaction_info['transaction_type'],
            'website' => $transaction_info['website'],
            'original' => $transaction_info
        ];

        return $transaction;
    }
}

