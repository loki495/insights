<?php

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use App\Models\LinkedAccount;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public LinkedAccount $linkedAccount;

    public function mount(LinkedAccount $linkedAccount): void
    {
        $this->authorize('view', $linkedAccount);
        $this->linkedAccount = $linkedAccount;
    }

    public function pullData(): void
    {
        PullLinkedAccountTransactionsAction::run($this->linkedAccount);

        $this->redirectRoute('linked-accounts.accounts.index', $this->linkedAccount);
    }

    public function with(): array
    {
        return [
            'accounts' => $this->linkedAccount->accounts()->paginate(10),
        ];
    }

    public function updateTrackingMode(int $accountId, string $trackingMode): void
    {
        if (! in_array($trackingMode, ['tracked', 'reference', 'excluded'], true)) {
            throw new InvalidArgumentException('Invalid tracking mode.');
        }

        $account = Account::findOrFail($accountId);
        $this->authorize('update', $account);
        $account->update(['tracking_mode' => $trackingMode]);
    }
}

?>
    <x-page-wrapper heading="Accounts" subheading="{{ $this->linkedAccount->provider_name }}" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index']">

        <x-slot name="actions">
            <x-button wire:click="pullData" class="w-full sm:w-auto">Pull Data</x-button>
        </x-slot>

        <x-responsive-table
            :items="$accounts ?? []"
            row-view="livewire.admin.accounts.partials.account-table-row"
            card-view="livewire.admin.accounts.partials.account-card"
            empty-message="No accounts found"
            :context="['linkedAccount' => $this->linkedAccount]"
        >
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th class="text-center">Name</x-table.th>
                    <x-table.th class="text-center">Current Balance</x-table.th>
                    <x-table.th class="text-center">Available Balance</x-table.th>
                    <x-table.th class="text-center">Tracking</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
        </x-responsive-table>

        @if($accounts)
        {{ $accounts->links() }}
        @endif

    </x-page-wrapper>
