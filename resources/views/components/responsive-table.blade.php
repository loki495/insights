@props([
    'items' => [],
    'rowView',
    'cardView',
    'emptyMessage' => 'No results found.',
    'context' => [],
    'loading' => true,
    'loadingTarget' => null,
])

@php
    $loadingTargetAttr = $loadingTarget ? 'wire:target="'.e($loadingTarget).'"' : '';
@endphp

<div class="hidden sm:flex flex-col gap-4 bg-zinc-100 dark:bg-white/10 p-4 rounded-xl w-full relative overflow-x-auto">
    <x-table {{ $attributes }}>
        @isset($head)
        <x-slot name="head">
            {{ $head }}
        </x-slot>
        @endisset
        <x-slot name="body">
            @if ($loading)
            <x-table.tr wire:loading {!! $loadingTargetAttr !!}>
                <x-table.td colspan="100">
                    <div class="absolute inset-0 z-10 flex items-start justify-center bg-white/70 dark:bg-zinc-900/70 rounded-xl">
                        <div class="mt-16 sticky left-1/2 top-32 -translate-x-1/2">
                            <flux:icon.loading class="w-16 h-16" />
                        </div>
                    </div>
                </x-table.td>
            </x-table.tr>
            @endif
            @forelse($items as $item)
                @include($rowView, array_merge($context, ['item' => $item]))
            @empty
                <x-table.tr wire:loading.remove>
                    <x-table.td colspan="100" class="text-center"><div class="mt-4">{{ $emptyMessage }}</div></x-table.td>
                </x-table.tr>
            @endforelse
        </x-slot>
    </x-table>
</div>

<div class="flex flex-col gap-2 sm:hidden w-full relative">
    @if ($loading)
    <div wire:loading {!! $loadingTargetAttr !!} class="absolute inset-0 z-10 flex items-start justify-center bg-white/70 dark:bg-zinc-900/70 rounded-xl">
        <div class="mt-16">
            <flux:icon.loading class="w-16 h-16" />
        </div>
    </div>
    @endif
    @forelse($items as $item)
        @include($cardView, array_merge($context, ['item' => $item]))
    @empty
        <div class="text-center text-zinc-500 dark:text-zinc-400 py-4">{{ $emptyMessage }}</div>
    @endforelse
</div>
