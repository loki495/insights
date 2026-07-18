<x-table>
    <x-slot name="body">
        <x-table.tr>
            <x-table.td class="!px-0 align-top">From:</x-table.td>
            <x-table.td class="!px-3 text-right">
                <span class="font-bold">{!! \Carbon\Carbon::parse($date_from)->format('n/j/y') !!}</span>
                {!! \Carbon\Carbon::parse($date_from)->format('g:ia') !!}
            </x-table.td>
        </x-table.tr>
        <x-table.tr>
            <x-table.td class="!px-0 align-top">To:</x-table.td>
            <x-table.td class="!px-3 text-right">
                <span class="font-bold">{!! \Carbon\Carbon::parse($date_to)->format('n/j/y') !!}</span>
                {!! \Carbon\Carbon::parse($date_to)->format('g:ia') !!}
            </x-table.td>
        </x-table.tr>
    </x-slot>
</x-table>
<x-table>
    <x-slot name="body">
        <x-table.tr>
            <x-table.td class="!px-0">Transactions:</x-table.td>
            <x-table.td class="text-right">{{ $count }}</x-table.td>
        </x-table.tr>
        <x-table.tr>
            <x-table.td class="!px-0">Total:</x-table.td>
            <x-table.td class="text-right">{!! currency(abs($total)) !!}</x-table.td>
        </x-table.tr>
    </x-slot>
</x-table>
