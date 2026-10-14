<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use Livewire\Volt\Component;

new class extends Component
{
    public Account $account;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account->linked_account);
        $this->authorize('view', $account);
        $this->account = $account;
    }

    public function pullData(): void
    {
        try {
            PullLinkedAccountTransactionsAction::run($this->account->linked_account);
        } catch (Throwable $exception) {
            if (config('app.debug')) {
                throw $exception;
            }

            report($exception);

            // Failure is already recorded on the LinkedAccount (last_sync_failed_at/
            // last_sync_error) and surfaced on the Linked Institutions page — nothing more to do
            // here than avoid a raw exception page for what was a manual, user-initiated retry.
        }

        $this->dispatch('transactions-updated');
    }
}

?>
    <x-page-wrapper heading="Account Transactions" :subheading="$this->account->linked_account->provider_name . ' - ' . $this->account->display_name . ' - ' . $this->account->mask" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index', 'Accounts' => route('linked-accounts.accounts.index', $this->account->linked_account) ]">

        <x-slot name="actions">
            @unless($this->account->linked_account->is_demo)
            <x-button wire:click="pullData" class="w-full sm:w-auto">Pull Data</x-button>
            @endunless
        </x-slot>

        <livewire:components.transactions :account="$account"></livewire:components.transactions>

    </x-page-wrapper>
