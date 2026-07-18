@php
    $suggestion = count($transaction['categories']) === 0
        ? ($merchantSuggestions[$transaction['merchant_name']] ?? null)
        : null;
@endphp

<div class="flex gap-2 items-center flex-wrap" x-show="!optimisticCategories[{{ $transaction['id'] }}]">

    @if($transaction['original']['manual'] ?? false)
    <div class="pointer-events-none text-xs p-1 h-auto relative rounded-lg shadow-lg bg-green-800">
        <div class="p-0 text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)]">Manual</div>
    </div>
    @endif

    @foreach($transaction['categories'] as $category)
    <button
        type="button"
        @click="$dispatch('edit-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ htmlQuotes($transaction['name']) }}', transaction_amount: '{{ currency($transaction['amount'], $transaction['currency'], 1) }}', category_id: {{ $category['id'] }} })"
        class="cursor-pointer text-xs p-1 h-auto rounded-lg shadow-lg text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)]"
        style="background-color: {{ $category['color'] }}"
    >{{ $category['fullName'] }}</button>
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
    <div class="text-xs p-1 h-auto rounded-lg shadow-lg opacity-60 animate-pulse text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)]" :style="`background-color: ${optimisticCategories[{{ $transaction['id'] }}]?.color}`">
        <span x-text="optimisticCategories[{{ $transaction['id'] }}]?.full_name"></span>
    </div>
</div>
