<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
    #[\Override]
    protected $signature = 'transactions:reconcile {linked_account_id} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    #[\Override]
    protected $description = 'Reconcile already saved transactions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $linked_account_id = $this->argument('linked_account_id');
        $force = (bool) $this->argument('force');

        $linked_account = LinkedAccount::with('accounts')
            ->where('id', $linked_account_id)->first();

        if (! $linked_account) {
            $this->error("Linked account {$linked_account_id} not found.");

            return self::FAILURE;
        }

        ReconcileLinkedAccountTransactions::run($linked_account, $force);

        return self::SUCCESS;
    }
}
