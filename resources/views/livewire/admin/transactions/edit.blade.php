<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    public $account_id = null;
    public $transaction_id = null;

    public $amount = 0;
    public $date = '';
    public $categories = [];
    public $currency = 'USD';
    public $merchant_name = '';
    public $name = '';

    public function mount(?Account $account, ?Transaction $transaction): void
    {
        $this->account_id = $account->id;
        if ($transaction) {
            $this->transaction_id = $transaction->id;
            $this->account_id = $transaction->account_id;
            $this->amount = $transaction->amount;
            $this->date = $transaction->created_at->format('Y-m-d\TH:i');
            $this->categories = $transaction->categories->pluck('id')->toArray();
            $this->currency = $transaction->currency;
            $this->merchant_name = $transaction->merchant_name;
            $this->name = $transaction->name;
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
        ];
    }

    public function save() {
        $original = [
            'manual' => true
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
            'original' => $original
        ]);

        $transaction->categories()->sync($this->categories);

        if ($this->account_id) {
            return redirect()->route('linked-accounts.accounts.show', [
                'linkedAccount' => Account::find($this->account_id)->linked_account,
                'account' => $this->account_id
            ]);
        }

        return redirect()->route('reports.category.index.index');
    }

}

?>
    <x-page-wrapper heading="{{ $transaction_id ? 'Edit' : 'Create' }} Transaction{{ $transaction_id ? ' - #' . $transaction_id : '' }}" subheading="" :breadcrumbs="[]">

        <div class="mb-4 max-w-[400px]">
            <x-table>
                <x-slot name="body">
                    <x-table.tr>
                        <x-table.th class="text-left">Account</x-table.th>
                        <x-table.td>
                            <flux:select wire:model="account_id" clearable>
                                @foreach($accounts as $account)
                                <flux:select.option value="{{ $account->id }}">{{ $account->linked_account->provider_name }} - {{ $account->name }} ({{ ucwords($account->subtype) }})</flux:select.option>
                                @endforeach
                            </flux:select>
                        </x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Categories</x-table.th>
                        <x-table.td>
                            <div class="flex gap-4 items-end">
                                <select wire:model="categories" class="border border-zinc-300 rounded-xl p-4 w-96">
                                    @foreach($all_categories as $category)
                                    <option value="{{ $category->id }}" class="p-2">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Name</x-table.th>
                        <x-table.td><flux:input wire:model="name" /></x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Merchant Name</x-table.th>
                        <x-table.td><flux:input wire:model="merchant_name" /></x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Amount</x-table.th>
                        <x-table.td><flux:input wire:model="amount" type="number" step="0.01" /></x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Date</x-table.th>
                        <x-table.td><flux:input wire:model="date" type="datetime-local" /></x-table.td>
                    </x-table.tr>
                </x-slot>
            </x-table>

            <x-button wire:click="save" class="mt-4">Save</x-button>
        </div>

    </x-page-wrapper>
