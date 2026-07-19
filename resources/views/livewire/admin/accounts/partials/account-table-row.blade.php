<x-table.tr>
    <x-table.td>{{ $item['name'] }}</x-table.td>
    <x-table.td>
        <flux:input wire:change="updateNickname({{ $item['id'] }}, $event.target.value)" value="{{ $item['nickname'] }}" placeholder="(none)" size="sm" />
    </x-table.td>
    <x-table.td class="text-right">{!! currency($item['current_balance']) !!}</x-table.td>
    <x-table.td class="text-right">{!! currency($item['available_balance']) !!}</x-table.td>
    <x-table.td>
        <flux:select wire:change="updateTrackingMode({{ $item['id'] }}, $event.target.value)" size="sm">
            <flux:select.option value="tracked" :selected="$item['tracking_mode'] === 'tracked'">Tracked</flux:select.option>
            <flux:select.option value="reference" :selected="$item['tracking_mode'] === 'reference'">Reference only</flux:select.option>
            <flux:select.option value="excluded" :selected="$item['tracking_mode'] === 'excluded'">Excluded</flux:select.option>
        </flux:select>
    </x-table.td>
    <x-table.td>
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
    </x-table.td>
</x-table.tr>
