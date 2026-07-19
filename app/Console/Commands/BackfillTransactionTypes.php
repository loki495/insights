<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\MatchTransferPairsAction;
use App\Models\Transaction;
use Illuminate\Console\Command;

class BackfillTransactionTypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:backfill-types';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Classify type (income/expense/transfer) on existing transactions and match internal transfer pairs';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $counts = ['income' => 0, 'expense' => 0, 'transfer' => 0, 'adjustment' => 0];

        Transaction::query()->chunkById(200, function ($transactions) use (&$counts): void {
            foreach ($transactions as $transaction) {
                $transaction->refreshType();
                $counts[$transaction->type] = ($counts[$transaction->type] ?? 0) + 1;
            }
        });

        $this->info('Type classification complete:');
        foreach ($counts as $type => $count) {
            $this->line("  {$type}: {$count}");
        }

        $result = MatchTransferPairsAction::run();
        $unpaired = Transaction::where('type', 'transfer')->whereNull('transfer_pair_id')->count();

        $this->info('Transfer pairing complete:');
        $this->line("  matched pairs: {$result['matched_pairs']}");
        $this->line("  flagged (investment/loan, needs manual review): {$result['flagged_investment_or_loan']}");
        $this->line("  unpaired: {$unpaired}");
    }
}
