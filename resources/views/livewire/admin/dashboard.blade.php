<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'linkedAccounts' => auth()->user()->linkedAccounts()->with('accounts')->get(),
        ];
    }
};

?>

<x-page-wrapper heading="Dashboard" subheading="Account Summaries">
    <div class="flex h-full w-full flex-1 flex-col gap-8 rounded-xl">
        @foreach ($linkedAccounts as $linkedAccount)
            <div wire:key="linked-account-{{ $linkedAccount->id }}" class="flex flex-col gap-4">
                <flux:heading size="lg" weight="semibold">{{ $linkedAccount->provider_name }}</flux:heading>
                
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($linkedAccount->accounts as $account)
                        <a 
                            href="{{ route('linked-accounts.accounts.show', [$linkedAccount, $account]) }}" 
                            wire:navigate
                            wire:key="account-{{ $account->id }}"
                            class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-neutral-300 hover:shadow-sm dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-neutral-600"
                        >
                            <div class="flex flex-col gap-1">
                                <flux:text size="sm" class="text-neutral-500 dark:text-neutral-400">
                                    {{ $account->official_name ?: $account->name }}
                                </flux:text>
                                <flux:heading size="md" weight="bold" class="truncate">
                                    {{ $account->name }}
                                </flux:heading>
                            </div>

                            <div class="mt-4 flex items-baseline justify-between">
                                <div class="text-2xl font-bold tracking-tight">
                                    {!! currency($account->current_balance, $account->currency) !!}
                                </div>
                                @if($account->available_balance && $account->available_balance != $account->current_balance)
                                    <flux:text size="xs" class="text-neutral-400 dark:text-neutral-500">
                                        Avail: {!! currency($account->available_balance, $account->currency, true) !!}
                                    </flux:text>
                                @endif
                            </div>

                            <div class="mt-4 flex items-center justify-between border-t border-neutral-100 pt-4 dark:border-neutral-700/50">
                                <flux:badge size="sm" variant="subtle">
                                    {{ $account->subtype ?: $account->type }}
                                </flux:badge>
                                <flux:icon name="chevron-right" size="sm" class="text-neutral-300 transition-transform group-hover:translate-x-1 dark:text-neutral-600" />
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if($linkedAccounts->isEmpty())
            <div class="flex flex-1 flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-200 p-12 text-center dark:border-neutral-700">
                <flux:heading size="lg" class="mb-2">No accounts linked yet</flux:heading>
                <flux:text class="mb-6">Link your first bank account to start tracking your finances.</flux:text>
                <flux:button href="{{ route('linked-accounts.index') }}" wire:navigate variant="primary">
                    Go to Linked Accounts
                </flux:button>
            </div>
        @endif
    </div>
</x-page-wrapper>
