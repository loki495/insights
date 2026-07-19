@php
    $typeStyles = [
        'income' => ['label' => 'Income', 'class' => 'bg-emerald-600'],
        'expense' => ['label' => 'Expense', 'class' => 'bg-zinc-500'],
        'transfer' => ['label' => 'Transfer', 'class' => 'bg-blue-600'],
        'adjustment' => ['label' => 'Adjustment', 'class' => 'bg-amber-600'],
    ];
    $style = $typeStyles[$transaction['type'] ?? null] ?? null;
@endphp

<template x-if="!optimisticTypes[{{ $transaction['id'] }}]">
    @if($style)
    <button type="button" data-transaction-id="{{ $transaction['id'] }}" @click="$dispatch('edit-type', { transaction_id: {{ $transaction['id'] }} })" class="cursor-pointer text-xs px-1.5 py-0.5 rounded-md text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)] {{ $style['class'] }}">{{ $style['label'] }}</button>
    @else
    <button type="button" data-transaction-id="{{ $transaction['id'] }}" @click="$dispatch('edit-type', { transaction_id: {{ $transaction['id'] }} })" class="cursor-pointer text-xs px-1.5 py-0.5 rounded-md text-nowrap border border-dashed border-zinc-400 text-zinc-500 dark:text-zinc-400">Unclassified</button>
    @endif
</template>

{{-- Optimistic preview while saveType()'s request is in flight — same "instant feedback,
     reconcile after" pattern already used for the category chip. --}}
<template x-if="optimisticTypes[{{ $transaction['id'] }}]">
    <button
        type="button"
        @click="$dispatch('edit-type', { transaction_id: {{ $transaction['id'] }} })"
        class="cursor-pointer text-xs px-1.5 py-0.5 rounded-md text-nowrap text-white opacity-60 animate-pulse [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)]"
        :class="{
            'bg-emerald-600': optimisticTypes[{{ $transaction['id'] }}] === 'income',
            'bg-zinc-500': optimisticTypes[{{ $transaction['id'] }}] === 'expense',
            'bg-blue-600': optimisticTypes[{{ $transaction['id'] }}] === 'transfer',
            'bg-amber-600': optimisticTypes[{{ $transaction['id'] }}] === 'adjustment',
        }"
        x-text="optimisticTypes[{{ $transaction['id'] }}].charAt(0).toUpperCase() + optimisticTypes[{{ $transaction['id'] }}].slice(1)"
    ></button>
</template>
