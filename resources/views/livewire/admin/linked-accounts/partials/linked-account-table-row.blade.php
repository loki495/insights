<x-table.tr class="{{ $item['closed_at'] ? 'opacity-50' : '' }}">
    <x-table.td>
        <flux:link :href="route('linked-accounts.accounts.index', $item['id'])" wire:navigate>{{ $item['provider_name'] }}</flux:link>
        @if($item['closed_at'])
        <span class="text-xs text-zinc-500 dark:text-zinc-400">(closed {{ \Illuminate\Support\Carbon::parse($item['closed_at'])->format('M j, Y') }})</span>
        @endif
        @if(count($item['accounts']))
        <div class="grid grid-cols-[auto_auto] py-2 text-xs text-zinc-500 dark:text-zinc-400">
            @foreach($item['accounts'] as $account)
                <span class="{{ $loop->even ? 'bg-zinc-50 dark:bg-white/5' : '' }} px-2 py-1">{{ $account['display_name'] }}:</span>
                <span class="{{ $loop->even ? 'bg-zinc-50 dark:bg-white/5' : '' }} px-2 py-1 text-right">
                    {!! currency($account['current_balance']) !!}
                    @if($account['type'] === 'credit' && $account['available_balance'] !== null)
                    <span class="text-zinc-400 dark:text-zinc-500">({!! currency($account['available_balance'], flat: true) !!} available)</span>
                    @endif
                </span>
            @endforeach
        </div>
        @endif
    </x-table.td>
    <x-table.td>
        @unless($item['closed_at'])
        @include('livewire.admin.linked-accounts.partials.auto-pull-controls', ['item' => $item])
        @endunless
    </x-table.td>
    <x-table.td>
        <div class="flex gap-2">
            <x-button icon="list-bullet" title="View Accounts" class="cursor-pointer hover:bg-zinc-200" href="{{ route('linked-accounts.accounts.index', $item['id']) }}" wire:navigate></x-button>
            <x-button icon="arrow-path" title="Update Access Token" class="cursor-pointer !bg-orange-600 hover:!bg-orange-500 dark:!bg-orange-700 dark:!border-orange-700 dark:hover:!bg-orange-600" wire:click="linkAccount({{ $item['id'] }})"></x-button>
            @if($item['closed_at'])
            <x-button icon="arrow-uturn-left" title="Reopen" class="cursor-pointer !bg-green-600 hover:!bg-green-500 dark:!bg-green-700 dark:!border-green-700 dark:hover:!bg-green-600" wire:click="reopen({{ $item['id'] }})"></x-button>
            @else
            <x-button icon="x-circle" title="Close" class="cursor-pointer !bg-red-600 hover:!bg-red-500 dark:!bg-red-700 dark:!border-red-700 dark:hover:!bg-red-600" wire:click="close({{ $item['id'] }})"></x-button>
            @endif
        </div>
    </x-table.td>
</x-table.tr>
