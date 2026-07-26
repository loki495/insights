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
        // Plaid's own account_id is the stable identity to match on — it doesn't change just
        // because our stored name/official_name diverges from what Plaid is currently sending
        // (e.g. our own cleanPlaidText() normalization, or Plaid renaming a product). Matching
        // by name first caused a real duplicate: Plaid kept sending a corrupted account name
        // after we'd already cleaned the stored one, so the name-based lookup stopped finding
        // the existing row and a second Account was created for the same Plaid account_id.
        // Falling back to the old descriptive-field match only when the id lookup misses still
        // covers the (separately real) case of Plaid reissuing a new account_id for what is
        // otherwise clearly the same account (see the "keeps the same available/current
        // mapping" test below, which changes account_id on purpose).
        $account = Account::query()
            ->where('linked_account_id', $linked_account->id)
            ->where('plaid_account_id', $account_info['account_id'])
            ->first();

        if (! $account) {
            $account = Account::query()
                ->where('linked_account_id', $linked_account->id)
                ->where('name', $account_info['name'])
                ->where('official_name', $account_info['official_name'])
                ->where('type', $account_info['type'])
                ->where('subtype', $account_info['subtype'])
                ->where('mask', $account_info['mask'])
                ->first();
        }

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
