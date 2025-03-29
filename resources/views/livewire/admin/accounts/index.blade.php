<?php

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public LinkedAccount $linkedAccount;

    public function mount(LinkedAccount $linkedAccount): void
    {
        $this->linkedAccount = $linkedAccount;
    }

    public function pullData(): void
    {
        PullLinkedAccountTransactionsAction::run($this->linkedAccount);
    }

    public function with(): array
    {
        return [
            'accounts' => Account::paginate(10),
        ];
    }
}

?>
    <x-page-wrapper heading="Linked Institutions" subheading="Accounts" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index']">

        <x-slot name="actions">
            <x-button wire:click="pullData">Pull Data</x-button>
        </x-slot>

        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Current Balance</x-table.th>
                    <x-table.th>Available Balance</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
            @foreach($accounts ?? [] as $account)
            <x-table.tr>
                <x-table.td>{{ $account['name'] }}</x-table.td>
                <x-table.td>{!! currency($account['current_balance']) !!}</x-table.td>
                <x-table.td>{!! currency($account['available_balance']) !!}</x-table.td>
                <x-table.td>
                    <x-button icon="list-bullet" title="View Transactions" class="!bg-yellow-400 stroke-slate-800 hover:!bg-yellow-300 cursor-pointer" href="{{ route('linked-accounts.accounts.show', [ $this->linkedAccount, $account['id'] ]) }}" wire:navigate></x-button>
                </x-table.td>
            </x-table.tr>
            @endforeach
            </x-slot>
        </x-table>

        @if($accounts)
        {{ $accounts->links() }}
        @endif

    </x-page-wrapper>
