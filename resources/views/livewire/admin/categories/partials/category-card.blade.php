@php
    $childIds = $item->children->pluck('id')->toArray();
    $isRoot = $item->parent_id === null || $item->parent_id === 0;
@endphp

<div
    wire:key="mobile-cat-{{ $item->id }}"
    class="row-category-{{ $item->id }} flex flex-col gap-1 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none"
    x-data="{
        children: {{ json_encode($childIds) }},
        cat_id: '{{ $item->id }}',
        parent_cat_id: '{{ $item->parent_id }}',
        is_root: {{ $isRoot ? 'true' : 'false' }}
    }"
    x-show="open[parent_cat_id] === true || is_root || {{ $search ? 'true' : 'false' }}"
    style="margin-left: {{ $search ? 0 : $item->depth * 12 }}px;"
    @click="toggleCat(cat_id)"
    x-cloak
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="text-[11px] text-zinc-500 dark:text-zinc-400">#{{ $item->id }}</div>
            <div class="font-medium break-words">{{ $item->name }}</div>
            @if($item->parent)
            <div class="text-xs px-2 py-0.5 rounded inline-block mt-1" style="background-color: {{ $item->parent->color }}">
                {{ $item->parent->fullName }}
            </div>
            @endif
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <div class="w-4 h-4 rounded shrink-0" style="background-color: {{ $item->color }}"></div>
            @if(!$search && $item->has_children)
            <flux:icon.chevron-down class="size-4 text-zinc-500 dark:text-zinc-400 shrink-0" />
            @endif
        </div>
    </div>

    @if($item->description)
    <div class="text-sm text-zinc-600 dark:text-zinc-300">{{ $item->description }}</div>
    @endif

    <div class="flex gap-2 items-center justify-end pt-1">
        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('categories.edit', $item) }}"></x-button>
        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $item->id }})"></x-button>
    </div>
</div>
