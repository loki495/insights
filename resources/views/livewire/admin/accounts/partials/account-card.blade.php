<div wire:key="mobile-account-{{ $item['id'] }}" class="flex flex-col gap-1 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none">
    <div class="flex items-start justify-between gap-2">
        <div class="font-medium break-words">{{ $item['name'] }}</div>
        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer shrink-0" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400 shrink-0">Nickname</div>
        <flux:input wire:change="updateNickname({{ $item['id'] }}, $event.target.value)" value="{{ $item['nickname'] }}" placeholder="(none)" size="sm" />
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Current Balance</div>
        <div class="font-semibold">{!! currency($item['current_balance']) !!}</div>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Available Balance</div>
        <div class="font-semibold">{!! currency($item['available_balance']) !!}</div>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Tracking</div>
        <flux:select wire:change="updateTrackingMode({{ $item['id'] }}, $event.target.value)" size="sm">
            <flux:select.option value="tracked" :selected="$item['tracking_mode'] === 'tracked'">Tracked</flux:select.option>
            <flux:select.option value="reference" :selected="$item['tracking_mode'] === 'reference'">Reference only</flux:select.option>
            <flux:select.option value="excluded" :selected="$item['tracking_mode'] === 'excluded'">Excluded</flux:select.option>
        </flux:select>
    </div>
</div>
