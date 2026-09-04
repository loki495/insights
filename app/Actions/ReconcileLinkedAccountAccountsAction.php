<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AccountDisabledReason;
use App\Models\LinkedAccount;
use Illuminate\Support\Facades\DB;

final class ReconcileLinkedAccountAccountsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $accountData
     */
    public static function run(LinkedAccount $linkedAccount, array $accountData, bool $restoreManuallyDisabled = false): void
    {
        DB::transaction(function () use ($linkedAccount, $accountData, $restoreManuallyDisabled): void {
            $activePlaidAccountIds = [];

            foreach ($accountData as $accountInfo) {
                UpdateAccountAction::run($accountInfo, $linkedAccount, $restoreManuallyDisabled);
                $activePlaidAccountIds[] = $accountInfo['account_id'];
            }

            $linkedAccount->accounts()
                ->whereNull('disabled_at')
                ->whereNotIn('plaid_account_id', $activePlaidAccountIds)
                ->update([
                    'disabled_at' => now(),
                    'disabled_reason' => AccountDisabledReason::MissingFromProvider->value,
                ]);
        });
    }
}
