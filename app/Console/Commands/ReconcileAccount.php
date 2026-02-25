<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Actions\ReconcileLinkedAccountTransactions;
use App\Models\LinkedAccount;
use Illuminate\Console\Command;

class ReconcileAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:reconcile {linked_account_id} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile already saved transactions';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $linked_account_id = $this->argument('linked_account_id');
        $force = $this->argument('force') ? true : false;

        $linked_account = LinkedAccount::with('accounts')
            ->where('id', $linked_account_id)->first();

        ReconcileLinkedAccountTransactions::run($linked_account, $force);
    }
}
