<div wire:key="mobile-account-{{ $item['id'] }}" class="flex flex-col gap-1 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none">
    <div class="flex items-start justify-between gap-2">
        <div class="font-medium break-words">{{ $item['name'] }}</div>
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer shrink-0" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Current Balance</div>
        <div class="font-semibold">{!! currency($item['current_balance']) !!}</div>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Available Balance</div>
        <div class="font-semibold">{!! currency($item['available_balance']) !!}</div>
    </div>
</div>
