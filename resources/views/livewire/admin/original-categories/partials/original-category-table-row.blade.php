<x-table.tr class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
    <x-table.td class="text-left">{{ $item->plaid_id }}</x-table.td>
    <x-table.td class="text-left">
        <div>
            {{ $item->name }}
        </div>
        <div class="text-xs italic">
            {{ $item->pf_detailed }}
        </div>
    </x-table.td>
    <x-table.td class="text-left">{{ $item->full_path }}</x-table.td>
    <x-table.td class="text-left">{!! currency($item->transactions_sum_amount ?? 0) !!}</x-table.td>
    <x-table.td class="text-left">
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('reports.category.index', $item) }}" wire:navigate></x-button>
    </x-table.td>
</x-table.tr>
