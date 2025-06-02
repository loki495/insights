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
    protected $signature = 'transactions:pull {linked_account_id?}';

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

        $linked_accounts = LinkedAccount::with('accounts');
        if ($linked_account_id) {
            $linked_accounts = $linked_accounts->where('id', $linked_account_id);
        }

        $linked_accounts
            ->get()
            ->each(function (LinkedAccount $linkedAccount) {
            PullLinkedAccountTransactionsAction::run($linkedAccount);
        });
    }
}
