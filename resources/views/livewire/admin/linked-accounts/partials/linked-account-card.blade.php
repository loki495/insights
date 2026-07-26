<div wire:key="mobile-linked-account-{{ $item['id'] }}" class="flex flex-col gap-2 p-3 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none {{ $item['closed_at'] ? 'opacity-50' : '' }}">
    <div class="flex items-start justify-between gap-2">
        <div class="font-medium break-words">
            <flux:link :href="route('linked-accounts.accounts.index', $item['id'])" wire:navigate>{{ $item['provider_name'] }}</flux:link>
            @if($item['closed_at'])
            <div class="text-xs text-zinc-500 dark:text-zinc-400">(closed {{ \Illuminate\Support\Carbon::parse($item['closed_at'])->format('M j, Y') }})</div>
            @endif
        </div>
        <div class="flex gap-2 shrink-0">
            <x-button icon="list-bullet" title="View Accounts" class="cursor-pointer" href="{{ route('linked-accounts.accounts.index', $item['id']) }}" wire:navigate></x-button>
            <x-button icon="arrow-path" title="Update Access Token" class="cursor-pointer !bg-orange-600 hover:!bg-orange-500 dark:!bg-orange-700 dark:!border-orange-700 dark:hover:!bg-orange-600" wire:click="linkAccount({{ $item['id'] }})"></x-button>
            @if($item['closed_at'])
            <x-button icon="arrow-uturn-left" title="Reopen" class="cursor-pointer !bg-green-600 hover:!bg-green-500 dark:!bg-green-700 dark:!border-green-700 dark:hover:!bg-green-600" wire:click="reopen({{ $item['id'] }})"></x-button>
            @else
            <x-button icon="x-circle" title="Close" class="cursor-pointer !bg-red-600 hover:!bg-red-500 dark:!bg-red-700 dark:!border-red-700 dark:hover:!bg-red-600" wire:click="close({{ $item['id'] }})"></x-button>
            @endif
        </div>
    </div>

    @if(count($item['accounts']))
    <div class="flex flex-col py-2">
        @foreach($item['accounts'] as $account)
        <div class="flex items-center justify-between gap-2 text-sm px-2 py-1 {{ $loop->even ? 'bg-zinc-50 dark:bg-white/5' : '' }}">
            <span class="text-zinc-600 dark:text-zinc-300">{{ $account['display_name'] }}</span>
            <span class="font-medium text-right">
                {!! currency($account['current_balance']) !!}
                @if($account['type'] === 'credit' && $account['available_balance'] !== null)
                <span class="block text-xs font-normal text-zinc-400 dark:text-zinc-500">({!! currency($account['available_balance'], flat: true) !!} available)</span>
                @endif
            </span>
        </div>
        @endforeach
    </div>
    @endif

    @unless($item['closed_at'])
    @include('livewire.admin.linked-accounts.partials.auto-pull-controls', ['item' => $item])
    @endunless
</div>
