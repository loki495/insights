<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public $account_id = null;

    public $transaction_id = null;

    public $amount = 0;

    public $date = '';

    public $categories = [];

    public $currency = 'USD';

    public $merchant_name = '';

    public $name = '';

    public ?string $type = null;

    public ?int $transfer_pair_id = null;

    public string $pair_search = '';

    public function mount(?Account $account, ?Transaction $transaction): void
    {
        if ($account && $account->id) {
            $this->authorize('view', $account);
            $this->account_id = $account->id;
        }

        if ($transaction && $transaction->id) {
            $this->authorize('view', $transaction);
            $this->transaction_id = $transaction->id;
            $this->account_id = $transaction->account_id;
            $this->amount = $transaction->amount;
            $this->date = $transaction->created_at->format('Y-m-d\TH:i');
            $this->categories = $transaction->categories->pluck('id')->toArray();
            $this->currency = $transaction->currency;
            $this->merchant_name = $transaction->merchant_name;
            $this->name = $transaction->name;
            $this->type = $transaction->type;
            $this->transfer_pair_id = $transaction->transfer_pair_id;
        }
    }

    public function with(): array
    {
        $categories = Category::query()
            ->with('children')
            ->with('parent')
            ->with('transactions')
            ->orderBy('parent_id')
            ->get();

        $accounts = auth()
            ->user()
            ->accounts()
            ->with('linked_account')
            ->get();

        return [
            'all_categories' => $categories,
            'accounts' => $accounts,
            'transferPair' => $this->transfer_pair_id ? Transaction::with('account')->find($this->transfer_pair_id) : null,
        ];
    }

    #[Computed]
    public function pairCandidates()
    {
        if (! $this->transaction_id || $this->type !== 'transfer' || $this->transfer_pair_id || trim($this->pair_search) === '') {
            return collect();
        }

        return Transaction::query()
            ->where('id', '!=', $this->transaction_id)
            ->where('account_id', '!=', $this->account_id)
            ->where('type', 'transfer')
            ->whereNull('transfer_pair_id')
            ->where(function ($query) {
                $term = '%'.$this->pair_search.'%';
                $query->where('name', 'like', $term)
                    ->orWhere('merchant_name', 'like', $term);
            })
            ->with('account')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function pairWith(int $otherTransactionId): void
    {
        $transaction = Transaction::findOrFail($this->transaction_id);
        $this->authorize('update', $transaction);

        $other = Transaction::findOrFail($otherTransactionId);
        $this->authorize('update', $other);

        if ($other->account_id === $transaction->account_id) {
            throw new InvalidArgumentException('Cannot pair two transactions from the same account.');
        }

        $transaction->update(['transfer_pair_id' => $other->id]);
        $other->update(['transfer_pair_id' => $transaction->id]);

        $this->transfer_pair_id = $other->id;
        $this->pair_search = '';
    }

    public function unpair(): void
    {
        $transaction = Transaction::findOrFail($this->transaction_id);
        $this->authorize('update', $transaction);

        if ($transaction->transfer_pair_id) {
            Transaction::where('id', $transaction->transfer_pair_id)->update(['transfer_pair_id' => null]);
        }

        $transaction->update(['transfer_pair_id' => null]);
        $this->transfer_pair_id = null;
    }

    public function save()
    {
        if ($this->transaction_id) {
            $transaction = Transaction::findOrFail($this->transaction_id);
            $this->authorize('update', $transaction);
        }

        if ($this->account_id) {
            $account = Account::findOrFail($this->account_id);
            $this->authorize('update', $account);
        }

        $original = [
            'manual' => true,
        ];

        $transaction = Transaction::updateOrCreate([
            'id' => $this->transaction_id,
        ], [
            'account_id' => $this->account_id,
            'created_at' => $this->date,
            'name' => $this->name,
            'amount' => $this->amount,
            'merchant_name' => $this->merchant_name,
            'currency' => $this->currency,
            'original' => $original,
            'type' => $this->type,
        ]);

        $transaction->categories()->sync($this->categories);

        if ($this->account_id) {
            return redirect()->route('linked-accounts.accounts.show', [
                'linkedAccount' => Account::find($this->account_id)->linked_account,
                'account' => $this->account_id,
            ]);
        }

        return redirect()->route('reports.category.index.index');
    }
}

?>
    <x-page-wrapper heading="{{ $transaction_id ? 'Edit' : 'Create' }} Transaction{{ $transaction_id ? ' - #' . $transaction_id : '' }}" subheading="" :breadcrumbs="[]">

        <div class="mb-4 w-full max-w-xl flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Account</label>
                <flux:select wire:model="account_id" clearable>
                    @foreach($accounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->linked_account->provider_name }} - {{ $account->name }} ({{ ucwords($account->subtype) }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Categories</label>
                <select wire:model="categories" class="border border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 rounded-xl p-4 w-full">
                    @foreach($all_categories as $category)
                    <option value="{{ $category->id }}" class="p-2">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Type</label>
                <flux:select wire:model.live="type" clearable>
                    <flux:select.option value="income">Income</flux:select.option>
                    <flux:select.option value="expense">Expense</flux:select.option>
                    <flux:select.option value="transfer">Transfer</flux:select.option>
                    <flux:select.option value="adjustment">Adjustment</flux:select.option>
                </flux:select>
            </div>

            @if($transaction_id && $type === 'transfer')
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Transfer Pair</label>

                @if($transferPair)
                <div class="flex items-center justify-between gap-2 border border-zinc-300 dark:border-zinc-600 rounded-xl p-3">
                    <span>{{ $transferPair->name }} ({{ $transferPair->account->name }}, {{ $transferPair->created_at->format('M j, Y') }})</span>
                    <flux:button size="sm" variant="danger" wire:click="unpair">Unpair</flux:button>
                </div>
                @else
                <div class="flex flex-col gap-2">
                    <flux:input wire:model.live.debounce="pair_search" placeholder="Search for the other leg by name/merchant..." />
                    <div class="flex flex-col gap-1 max-h-64 overflow-y-auto">
                        @foreach($this->pairCandidates as $candidate)
                        <button
                            type="button"
                            wire:click="pairWith({{ $candidate->id }})"
                            class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-zinc-200 dark:border-zinc-600 hover:bg-zinc-100 dark:hover:bg-white/10"
                        >
                            {{ $candidate->name }} &mdash; {{ $candidate->account->name }}, {{ $candidate->created_at->format('M j, Y') }} ({{ $candidate->amount }})
                        </button>
                        @endforeach
                        @if(trim($pair_search) !== '' && $this->pairCandidates->isEmpty())
                        <div class="text-sm text-zinc-500 dark:text-zinc-400 px-2 py-1.5">No matching unpaired transfers found.</div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            @endif

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Name</label>
                <flux:input wire:model="name" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Merchant Name</label>
                <flux:input wire:model="merchant_name" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Amount</label>
                <flux:input wire:model="amount" type="number" step="0.01" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Date</label>
                <flux:input wire:model="date" type="datetime-local" />
            </div>

            <x-button wire:click="save" class="w-full sm:w-auto">Save</x-button>
        </div>

    </x-page-wrapper>
