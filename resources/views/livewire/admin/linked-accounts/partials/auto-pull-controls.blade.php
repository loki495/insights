<div
    class="flex flex-col gap-1"
    x-data="{
        enabled: {{ $item['auto_pull_enabled'] ? 'true' : 'false' }},
        value: {{ $item['auto_pull_interval_value'] }},
        unit: '{{ $item['auto_pull_interval_unit'] }}',
        save() {
            $wire.updateAutoPull({{ $item['id'] }}, this.enabled, this.value, this.unit);
        },
    }"
>
    <flux:field variant="inline">
        <flux:checkbox x-model="enabled" @change="save()" />
        <flux:label>Auto-Pull</flux:label>
    </flux:field>
    <div class="flex items-center gap-1 text-sm" x-show="enabled" x-cloak>
        <span class="text-zinc-500 dark:text-zinc-400 shrink-0">every</span>
        <x-input type="number" min="1" x-model.number="value" @change="save()" class="!w-16 shrink-0"></x-input>
        <flux:select x-model="unit" @change="save()" class="w-24 shrink-0">
            <flux:select.option value="hours">hours</flux:select.option>
            <flux:select.option value="days">days</flux:select.option>
        </flux:select>
    </div>
    @if($item['last_pulled_at'])
    <span class="text-xs text-zinc-500 dark:text-zinc-400">Last pulled {{ \Illuminate\Support\Carbon::parse($item['last_pulled_at'])->diffForHumans() }}</span>
    @endif
    @if($item['last_sync_failed_at'])
    <span class="text-xs text-red-600 dark:text-red-400" title="{{ $item['last_sync_error'] }}">Last sync failed {{ \Illuminate\Support\Carbon::parse($item['last_sync_failed_at'])->diffForHumans() }}</span>
    @endif
</div>
