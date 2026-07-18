<x-table.tr>
    <x-table.td>{{ $item['name'] }}</x-table.td>
    <x-table.td class="text-right">{!! currency($item['current_balance']) !!}</x-table.td>
    <x-table.td class="text-right">{!! currency($item['available_balance']) !!}</x-table.td>
    <x-table.td>
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
    </x-table.td>
</x-table.tr>
