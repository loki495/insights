<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class UpdateAccountAction
{
    public static function run(array $account_info, LinkedAccount $linked_account): void
    {
        $plaid = plaid();

        $account = Account::query()
            ->where('name', $account_info['name'])
            ->where('official_name', $account_info['official_name'])
            ->where('type', $account_info['type'])
            ->where('subtype', $account_info['subtype'])
            ->where('mask', $account_info['mask'])
            ->whereHas('linked_account', function ($q) use ($linked_account) {
                $q->where('item_id', $linked_account->item_id);
            });

        if ($account->exists()) {

            DB::listen(function (QueryExecuted $query) {
                //dd($query->toRawSql());
            });

            $account->update([
                'plaid_account_id' => $account_info['account_id'],
                'mask' => $account_info['mask'],
                'name' => $account_info['name'],
                'official_name' => $account_info['official_name'],
                'type' => $account_info['type'],
                'subtype' => $account_info['subtype'],
                'currency' => $account_info['balances']['iso_currency_code'],
                'available_balance' => $account_info['balances']['available'],
                'current_balance' => $account_info['balances']['current'],
                'limit' => $account_info['balances']['limit'],
            ]);

            return;
        }

        Account::create([
            'linked_account_id' => $linked_account->id,
            'plaid_account_id' => $account_info['account_id'],
            'mask' => $account_info['mask'],
            'name' => $account_info['name'],
            'official_name' => $account_info['official_name'],
            'type' => $account_info['type'],
            'subtype' => $account_info['subtype'],
            'currency' => $account_info['balances']['iso_currency_code'],
            'available_balance' => $account_info['balances']['current'],
            'current_balance' => $account_info['balances']['available'],
            'limit' => $account_info['balances']['limit'],
        ]);
    }
}
