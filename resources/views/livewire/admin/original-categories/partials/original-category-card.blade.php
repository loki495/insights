<div wire:key="mobile-origcat-{{ $item->id }}" class="flex flex-col gap-1 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="text-[11px] text-zinc-500 dark:text-zinc-400">#{{ $item->plaid_id }}</div>
            <div class="font-medium break-words">{{ $item->name }}</div>
            <div class="text-xs italic text-zinc-500 dark:text-zinc-400 break-words">{{ $item->pf_detailed }}</div>
        </div>
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer shrink-0" href="{{ route('reports.category.index', $item) }}" wire:navigate></x-button>
    </div>

    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->full_path }}</div>

    <div class="font-semibold">{!! currency($item->transactions_sum_amount ?? 0) !!}</div>
</div>
