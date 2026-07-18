<div wire:key="mobile-txn-{{ $item['id'] }}" class="relative flex flex-col gap-1 p-2 pb-9 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none">
    <div class="flex items-start justify-between gap-2">
        <div class="text-[11px] text-zinc-500 dark:text-zinc-400 min-w-0">
            <span>{{ \Carbon\Carbon::parse($item['created_at'])->format('m/d/Y') }} #{{ $item['id'] }}</span>
            @if ($allow_accounts)
            <span>&middot; {{ $item['account']['name'] }} ({{ $item['account']['linked_account']['provider_name'] }})</span>
            @endif
        </div>
        <div class="text-right shrink-0">
            <div class="font-semibold">{!! currency($item['amount'], $item['currency']) !!}</div>
            @if ($showRunningBalance)
            <div class="{{ when($item['running_balance'] < 0, 'text-red-400', 'text-zinc-500 dark:text-zinc-400') }} text-[11px]">
                {!! currency($item['running_balance'], $item['currency'], 1) !!}
            </div>
            @endif
        </div>
    </div>

    <div class="min-w-0">
        <div class="font-medium break-words leading-tight">{{ $item['name'] }}</div>
        @if($item['originalCategory'] || $item['payment_channel'])
        <div class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-tight">
            @if($item['originalCategory']){{ $item['originalCategory']['full_path'] }}@endif
            @if($item['payment_channel'])({{ $item['payment_channel'] }})@endif
        </div>
        @endif
    </div>

    @include('livewire.components.partials.transaction-category-chips', ['transaction' => $item])

    <div class="absolute bottom-2 right-2 flex gap-1 items-center">
        <x-button icon="pencil" href="{{ route('transactions.edit', ['transaction' => $item['id'] ]) }}" />
        @if($item['original']['manual'] ?? false)
        <flux:icon.trash wire:confirm="Are you sure you want to delete this transaction?\n(#{{ $item['id'] }} - {{ htmlQuotes($item['name']) }})" variant="solid" wire:click="deleteTransaction({{ $item['id'] }})" class="cursor-pointer text-red-500 size-6 p-px font-bold hover:bg-white rounded-full" />
        @endif
    </div>
</div>
