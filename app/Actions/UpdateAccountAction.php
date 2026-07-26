<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\LinkedAccount;

final class UpdateAccountAction
{
    /**
     * @param  array<string, mixed>  $account_info
     */
    public static function run(array $account_info, LinkedAccount $linked_account): void
    {
        $account = Account::query()
            ->where('name', $account_info['name'])
            ->where('official_name', $account_info['official_name'])
            ->where('type', $account_info['type'])
            ->where('subtype', $account_info['subtype'])
            ->where('mask', $account_info['mask'])
            ->whereHas('linked_account', function ($q) use ($linked_account): void {
                $q->where('item_id', $linked_account->item_id);
            })
            ->first();

        if ($account) {
            // Deliberately a model instance's update(), not a query builder's — the latter
            // compiles straight to a raw SQL UPDATE and bypasses Eloquent attribute casting
            // entirely (App\Casts\MoneyCast on the balance columns would silently never run,
            // writing raw dollar amounts where cents are expected). Confirmed by a real test
            // failure when this was still a builder-level update.
            $account->update([
                'plaid_account_id' => $account_info['account_id'],
                'mask' => $account_info['mask'],
                'name' => cleanPlaidText($account_info['name']),
                'official_name' => cleanPlaidText($account_info['official_name']),
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
            'name' => cleanPlaidText($account_info['name']),
            'official_name' => cleanPlaidText($account_info['official_name']),
            'type' => $account_info['type'],
            'subtype' => $account_info['subtype'],
            'currency' => $account_info['balances']['iso_currency_code'],
            'available_balance' => $account_info['balances']['available'],
            'current_balance' => $account_info['balances']['current'],
            'limit' => $account_info['balances']['limit'],
        ]);
    }
}
