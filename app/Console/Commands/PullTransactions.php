<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\LinkedAccount;
use Illuminate\Console\Command;

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
    public function handle(): void
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

        $linked_accounts
            ->get()
            ->filter(fn (LinkedAccount $linkedAccount): bool => $linked_account_id || $linkedAccount->isAutoPullDue())
            ->each(function (LinkedAccount $linkedAccount) use ($force): void {
                PullLinkedAccountTransactionsAction::run($linkedAccount, null, $force);
            });

        $this->info('Transactions pulled');
    }
}
