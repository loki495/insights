@php
    $typeStyles = [
        'income' => ['label' => 'Income', 'class' => 'bg-emerald-600'],
        'expense' => ['label' => 'Expense', 'class' => 'bg-zinc-500'],
        'transfer' => ['label' => 'Transfer', 'class' => 'bg-blue-600'],
        'adjustment' => ['label' => 'Adjustment', 'class' => 'bg-amber-600'],
    ];
    $style = $typeStyles[$transaction['type'] ?? null] ?? null;
@endphp

@if($style)
<span class="text-xs px-1.5 py-0.5 rounded-md text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)] {{ $style['class'] }}">{{ $style['label'] }}</span>
@else
<span class="text-xs px-1.5 py-0.5 rounded-md text-nowrap border border-dashed border-zinc-400 text-zinc-500 dark:text-zinc-400">Unclassified</span>
@endif
