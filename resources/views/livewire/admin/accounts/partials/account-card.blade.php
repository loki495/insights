<div wire:key="mobile-account-{{ $item['id'] }}" class="flex flex-col gap-1 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none">
    <div class="flex items-start justify-between gap-2">
        <div class="font-medium break-words">{{ $item['name'] }}</div>
        <div class="flex shrink-0 gap-2">
            <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('linked-accounts.accounts.show', [ $linkedAccount, $item['id'] ]) }}" wire:navigate></x-button>
            <x-button icon="trash" title="Remove Account" class="cursor-pointer !bg-red-600 hover:!bg-red-500" wire:confirm="Remove this account from Insights? Its transaction history will be preserved. Re-enabling it requires relinking the institution and selecting this account again." wire:click="disableAccount({{ $item['id'] }})"></x-button>
        </div>
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400 shrink-0">Nickname</div>
        <flux:input wire:change="updateNickname({{ $item['id'] }}, $event.target.value)" value="{{ $item['nickname'] }}" placeholder="(none)" size="sm" />
    </div>

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Current Balance</div>
        <div class="font-semibold">{!! currency($item['current_balance']) !!}</div>
    </div>

    @if($item['available_balance'] !== null)
    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Available Balance</div>
        <div class="font-semibold">{!! currency($item['available_balance']) !!}</div>
    </div>
    @endif

    <div class="flex items-center justify-between gap-2">
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Tracking</div>
        <flux:select wire:change="updateTrackingMode({{ $item['id'] }}, $event.target.value)" size="sm">
            <flux:select.option value="tracked" :selected="$item['tracking_mode'] === 'tracked'">Tracked</flux:select.option>
            <flux:select.option value="reference" :selected="$item['tracking_mode'] === 'reference'">Reference only</flux:select.option>
            <flux:select.option value="excluded" :selected="$item['tracking_mode'] === 'excluded'">Excluded</flux:select.option>
        </flux:select>
    </div>
</div>
