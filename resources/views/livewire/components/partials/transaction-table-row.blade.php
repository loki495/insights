<x-table.tr wire:loading.remove class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
    <x-table.td class="text-center" x-show="selectMode">
        <flux:checkbox wire:model="selected_transactions" x-model="selected_transactions" value="{{ $item['id'] }}" class="selected_transaction" />
    </x-table.td>
    <x-table.td class="text-center">
        {{ \Carbon\Carbon::parse($item['created_at'])->format('m/d/Y') }}
        #{{ $item['id'] }}
    </x-table.td>
    @if ($allow_accounts)
    <x-table.td class="max-w-lg">
        <div>{{ $item['account']['name'] }}</div>
        <div class="text-sm text-zinc-400">{{ $item['account']['linked_account']['provider_name'] }}</div>
    </x-table.td>
    @endif
    <x-table.td class="max-w-lg">
        <div>
            {{ $item['name'] }}
            <br>
            @if($item['originalCategory'])
            <small>{{ $item['originalCategory']['full_path'] }}</small>
            @endif
            @if($item['payment_channel'])
            <small>({{ $item['payment_channel'] }}) </small>
            @endif

            <div class="mt-2">
                @include('livewire.components.partials.transaction-category-chips', ['transaction' => $item])
            </div>
        </div>
    </x-table.td>
    <x-table.td class="text-right">
        <div>{!! currency($item['amount'], $item['currency']) !!}</div>
    @if ($showRunningBalance)
        <div class='{{ when($item['running_balance'] < 0, 'text-red-400', 'text-zinc-300') }} text-sm'>{!! currency($item['running_balance'], $item['currency'], 1) !!}</div>
    @endif
    </x-table.td>

    <x-table.td class="text-right">
        <div class="flex gap-2 items-center">
                <x-button icon="pencil" href="{{ route('transactions.edit', ['transaction' => $item['id'] ]) }}" />
            @if($item['original']['manual'] ?? false)
                <flux:icon.trash wire:confirm="Are you sure you want to delete this transaction?\n(#{{ $item['id'] }} - {{ htmlQuotes($item['name']) }})" variant="solid" wire:click="deleteTransaction({{ $item['id'] }})" class="cursor-pointer text-red-500 size-6 p-px font-bold hover:bg-white rounded-full" />
            @endif
        </div>
    </x-table.td>
</x-table.tr>
