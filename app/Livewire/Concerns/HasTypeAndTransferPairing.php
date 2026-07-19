<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Transaction;
use InvalidArgumentException;

/**
 * Type (income/expense/transfer/adjustment) editing and transfer-pair search/pairing for the
 * transaction list's type-editor popup, plus bulk type assignment. Host components need
 * `chartNeedsRefresh` for the chart-refresh flag these actions set.
 */
trait HasTypeAndTransferPairing
{
    public function bulkAssignType(string $type, array $transaction_ids): void
    {
        if (! in_array($type, ['income', 'expense', 'transfer', 'adjustment'], true)) {
            throw new InvalidArgumentException('Invalid type.');
        }

        $transactions = Transaction::whereIn('id', $transaction_ids)->get();

        foreach ($transactions as $transaction) {
            $this->authorize('update', $transaction);
            $transaction->update(['type' => $type]);
        }

        $this->chartNeedsRefresh = true;
    }

    /**
     * Initial data for the type/pairing popup when it opens on a given transaction.
     */
    public function typeEditorData(int $transactionId): array
    {
        $transaction = Transaction::with('transferPair.account')->findOrFail($transactionId);
        $this->authorize('view', $transaction);

        return [
            'type' => $transaction->type,
            'pair' => $this->formatTransferPair($transaction->transferPair),
            'transaction' => [
                'name' => $transaction->name,
                'merchant_name' => $transaction->merchant_name,
                'amount' => currency($transaction->amount, $transaction->currency, true),
                'date' => $transaction->created_at->format('M j, Y'),
            ],
        ];
    }

    public function saveType(int $transactionId, string $type): array
    {
        if (! in_array($type, ['income', 'expense', 'transfer', 'adjustment'], true)) {
            throw new InvalidArgumentException('Invalid type.');
        }

        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('update', $transaction);

        // Pairing only makes sense for transfers — drop a stale pair rather than leave it
        // dangling once a transaction is corrected to income/expense/adjustment.
        if ($type !== 'transfer' && $transaction->transfer_pair_id) {
            $transaction->unpair();
        }

        $transaction->update(['type' => $type]);
        $this->chartNeedsRefresh = true;

        return [
            'type' => $transaction->type,
            'pair' => $this->formatTransferPair($transaction->refresh()->transferPair),
        ];
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public function searchTransferPairCandidates(int $transactionId, string $search): array
    {
        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('view', $transaction);

        return Transaction::searchUnpairedTransferCandidates($transactionId, $transaction->account_id, $search)
            ->map(fn (Transaction $candidate): ?array => $this->formatTransferPair($candidate))
            ->values()
            ->all();
    }

    public function pairTransaction(int $transactionId, int $otherTransactionId): ?array
    {
        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('update', $transaction);

        $other = Transaction::findOrFail($otherTransactionId);
        $this->authorize('update', $other);

        $transaction->pairWith($other);
        $this->chartNeedsRefresh = true;

        return $this->formatTransferPair($other);
    }

    public function unpairTransaction(int $transactionId): void
    {
        $transaction = Transaction::findOrFail($transactionId);
        $this->authorize('update', $transaction);

        $transaction->unpair();
        $this->chartNeedsRefresh = true;
    }

    private function formatTransferPair(?Transaction $pair): ?array
    {
        if (! $pair instanceof Transaction) {
            return null;
        }

        return [
            'id' => $pair->id,
            'label' => $pair->name.' — '.($pair->account?->display_name ?? 'Unknown account').', '.$pair->created_at->format('M j, Y'),
            'amount' => currency($pair->amount, $pair->currency, true),
        ];
    }
}
