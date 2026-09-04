<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\LinkedAccount;

final class RefreshLinkedAccountAccountsAction
{
    public static function run(LinkedAccount $linkedAccount, bool $restoreManuallyDisabled = false): void
    {
        $result = plaid()->getItemAccounts(data: [
            'access_token' => $linkedAccount->access_token,
        ]);

        ReconcileLinkedAccountAccountsAction::run(
            $linkedAccount,
            $result['accounts'],
            $restoreManuallyDisabled,
        );
    }
}
