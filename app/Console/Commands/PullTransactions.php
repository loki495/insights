<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\LinkedAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PullTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:pull {linked_account_id?} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull transactions from Plaid';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $linked_account_id = $this->argument('linked_account_id');
        $force = (bool) $this->argument('force');

        $linked_accounts = LinkedAccount::with('accounts')->whereNull('closed_at');

        if ($linked_account_id) {
            // An explicit id is a deliberate manual/CLI invocation — not subject to the
            // auto_pull_enabled/interval gating below, same as the UI's "Pull Data" button.
            $linked_accounts = $linked_accounts->where('id', $linked_account_id);
        } else {
            $linked_accounts = $linked_accounts->where('auto_pull_enabled', true);
        }

        $failures = 0;

        $linked_accounts
            ->get()
            ->filter(fn (LinkedAccount $linkedAccount): bool => $linked_account_id || $linkedAccount->isAutoPullDue())
            ->each(function (LinkedAccount $linkedAccount) use ($force, &$failures): void {
                try {
                    PullLinkedAccountTransactionsAction::run($linkedAccount, $force);
                } catch (\Throwable $e) {
                    $failures++;
                    Log::error('transactions:pull failed for linked account', [
                        'linked_account_id' => $linkedAccount->id,
                        'exception' => $e,
                    ]);
                }
            });

        if ($failures > 0) {
            $this->error("Transactions pulled with {$failures} institution(s) failing — see logs.");

            return self::FAILURE;
        }

        $this->info('Transactions pulled');

        return self::SUCCESS;
    }
}
