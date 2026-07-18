@php
    $childIds = $item->children->pluck('id')->toArray();
    $isRoot = $item->parent_id === null || $item->parent_id === 0;
@endphp

<x-table.tr
    class="row-category-{{ $item->id }} border-b border-zinc-300 dark:border-zinc-700 transition-opacity duration-300 ease-in"
    x-bind:class="{ 'hover:bg-zinc-100/10 dark:hover:bg-zinc-900/20 bg-zinc-400 dark:bg-zinc-400/10 cursor-pointer': {{ !$search && $item->has_children ? 'true' : 'false' }} }"
    x-ref="cat-{{ $item->id }}"
    x-data="{
        children: {{ json_encode($childIds) }},
        cat_id: '{{ $item->id }}',
        parent_cat_id: '{{ $item->parent_id }}',
        is_root: {{ $isRoot ? 'true' : 'false' }}
    }"
    x-show="open[parent_cat_id] === true || is_root || {{ $search ? 'true' : 'false' }}"
    style="padding-left: {{ $search ? 0 : $item->depth * 20 }}px;"
    @click="toggleCat(cat_id)"
    x-cloak
>
    <x-table.td>{{ $item->id }}</x-table.td>
    {{-- Parent Column --}}
    <x-table.td>
        <div class="flex gap-2">
            @if($item->parent)
            <div class="text-xs px-2 py-1 rounded" style="background-color: {{ $item->parent->color }}">
                {{ $item->parent->fullName }}
            </div>
            @endif
        </div>
    </x-table.td>

    {{-- Name --}}
    <x-table.td class="text-left">
        {{ $item->name }}
    </x-table.td>

    {{-- Description --}}
    <x-table.td class="text-left">
        <div class="flex justify-between">
            <div>{{ $item->description }}</div>
        </div>
    </x-table.td>

    {{-- Color --}}
    <x-table.td class="text-left">
        <div class="flex gap-4 justify-center items-center">
            {{ $item->color }}
            <div class="w-4 h-4" style="background-color: {{ $item->color }}"></div>
        </div>
    </x-table.td>

    {{-- Actions --}}
    <x-table.td class="text-left">
        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('categories.edit', $item) }}"></x-button>
        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $item->id }})"></x-button>
    </x-table.td>
</x-table.tr>
