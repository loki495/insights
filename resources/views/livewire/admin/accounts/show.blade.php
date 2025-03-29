<?php


use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public Account $account;

    public function mount(Account $account): void
    {
        $this->account = $account;
    }

    public function pullData(): void
    {
        PullLinkedAccountTransactionsAction::run($this->account->linkedAccount);
    }

    public function with(): array
    {
        return [
            'transactions' => $this->account->transactions()->paginate(10),
        ];
    }
}

?>
    <x-page-wrapper heading="Linked Institutions" subheading="Account Transactions" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index', 'Accounts' => route('linked-accounts.accounts.index', $this->account->linkedAccount) ]">

        <x-slot name="actions">
            <x-button wire:click="pullData">Pull Data</x-button>
        </x-slot>

        @if($transactions)
        {{ $transactions->links() }}
        @endif

        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Date</x-table.th>
                    <x-table.th>Source</x-table.th>
                    <x-table.th>Amount</x-table.th>
                    <x-table.th>Running Balance</x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
            @foreach($transactions ?? [] as $transaction)
                <x-table.tr class="hover:bg-zinc-900/20 border-b border-gray-800 cursor-normal">
                    <x-table.td>{{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }}</x-table.td>
                    <x-table.td class="max-w-lg">
                        {{ $transaction['name'] }}
                        <br>
                        <small>{{ $transaction['merchant_name'] }} ({{ $transaction['payment_channel'] }}) </small>
                    </x-table.td>
                    <x-table.td class="text-right">{!! currency(-$transaction['amount'], $transaction['currency']) !!}</x-table.td>
                    <x-table.td class="text-right">{!! currency($transaction['running_balance'], $transaction['currency']) !!}</x-table.td>
                </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>

        @if($transactions)
        {{ $transactions->links() }}
        @endif

    </x-page-wrapper>
