<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;
use App\Models\Account;

final class UpdateAccountAction
{
    static public function run(array $account_info, LinkedAccount $linkedAccount)
    {
        $account = Account::where('plaid_id', $account_info['account_id']);
        if ($account->exists()) {
            $account->update([
                'name' => $account_info['name'],
                'official_name' => $account_info['official_name'],
                'type' => $account_info['type'],
                'subtype' => $account_info['subtype'],
                'currency' => $account_info['balances']['iso_currency_code'],
                'current_balance' => $account_info['balances']['current'],
                'available_balance' => $account_info['balances']['available'],
                'limit' => $account_info['balances']['limit'],
            ]);

            return;
        }

        $account = Account::create([
            'name' => $account_info['name'],
            'official_name' => $account_info['official_name'],
            'type' => $account_info['type'],
            'subtype' => $account_info['subtype'],
            'currency' => $account_info['balances']['iso_currency_code'],
            'current_balance' => $account_info['balances']['available'],
            'available_balance' => $account_info['balances']['current'],
            'limit' => $account_info['balances']['limit'],
            'plaid_id' => $account_info['account_id'],
            'linked_account_id' => $linkedAccount->id,
        ]);
    }
}

