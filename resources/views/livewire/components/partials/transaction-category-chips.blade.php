@php
    $suggestion = count($transaction['categories']) === 0
        ? ($merchantSuggestions[$transaction['merchant_name']] ?? null)
        : null;
@endphp

<div class="flex gap-2 items-center flex-wrap" x-show="!optimisticCategories[{{ $transaction['id'] }}]">

    @if($transaction['original']['manual'] ?? false)
    <div class="pointer-events-none text-xs p-1 h-auto relative rounded-lg shoadow-lg bg-green-800">
        <div class="p-0 text-nowrap text-shadow-lg">Manual</div>
    </div>
    @endif

    @foreach($transaction['categories'] as $category)
    <div class="cursor-pointer text-xs p-1 h-auto relative rounded-lg shoadow-lg" style="background-color: {{ $category['color'] }}">
        <div @click="$dispatch('edit-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ htmlQuotes($transaction['name']) }}', transaction_amount: '{{ currency($transaction['amount'], $transaction['currency'], 1) }}', category_id: {{ $category['id'] }} })" class="p-0 text-nowrap text-shadow-lg">{{ $category['fullName'] }}</div>
        <flux:icon.x-mark variant="solid" wire:confirm="Are you sure you want to delete this category? (#{{ $category['pivot']['id'] }})" wire:click="deleteTransactionCategory({{ $category['pivot']['id'] }})" class="absolute z-20 cursor-pointer -right-3 text-red-500 -top-3 size-6 p-px font-bold text-shadow-lg bg-white/70 hover:bg-white rounded-full opacity-70 hover:opacity-100 transition-opacity" />
    </div>
    @endforeach

    @if($suggestion)
    <button
        type="button"
        title="Apply suggested category"
        @click="applyCategory({{ $transaction['id'] }}, {{ $suggestion['id'] }})"
        class="cursor-pointer text-xs p-1 h-auto rounded-lg border border-dashed text-nowrap"
        style="border-color: {{ $suggestion['color'] }}; color: {{ $suggestion['color'] }}"
    >+ {{ $suggestion['name'] }}?</button>
    @endif

    <flux:button size="xs" variant="subtle" inset @click="$dispatch('add-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ htmlQuotes($transaction['name']) }}', transaction_amount: {{ $transaction['amount'] }}, suggested_category_id: {{ $suggestion['id'] ?? 0 }} })" class="size-2" icon="plus"></flux:button>
</div>

<div class="flex gap-2 items-center flex-wrap" x-show="optimisticCategories[{{ $transaction['id'] }}]" x-cloak>
    <div class="text-xs p-1 h-auto rounded-lg opacity-60 animate-pulse text-nowrap text-shadow-lg" :style="`background-color: ${optimisticCategories[{{ $transaction['id'] }}]?.color}`">
        <span x-text="optimisticCategories[{{ $transaction['id'] }}]?.name"></span>
    </div>
</div>
