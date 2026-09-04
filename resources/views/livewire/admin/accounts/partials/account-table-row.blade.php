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
        <div class="flex gap-2">
            <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
            <x-button icon="trash" title="Remove Account" class="cursor-pointer !bg-red-600 hover:!bg-red-500" wire:confirm="Remove this account from Insights? Its transaction history will be preserved. Re-enabling it requires relinking the institution and selecting this account again." wire:click="disableAccount({{ $item['id'] }})"></x-button>
        </div>
    </x-table.td>
</x-table.tr>
