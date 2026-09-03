<div
    wire:key="mobile-rule-{{ $item->id }}"
    class="flex flex-col gap-2 p-2 rounded-xl bg-white dark:bg-white/10 border border-zinc-200 dark:border-transparent shadow-sm dark:shadow-none"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="font-medium break-words">{{ $item->name ?: '(unnamed rule)' }}</div>
            <div class="text-xs px-2 py-0.5 rounded inline-block mt-1" style="background-color: {{ $item->category->color }}">
                {{ $item->category->fullName }}
            </div>
        </div>
        <flux:switch wire:click="toggleActive({{ $item->id }})" :checked="$item->active"></flux:switch>
    </div>

    <div class="text-sm text-zinc-600 dark:text-zinc-300">
        @if($item->conditionGroups->count() > 1)
            {{ $item->match_type === 'any' ? 'Any group' : 'All groups' }} ({{ $item->conditionGroups->count() }} groups)
        @else
            {{ $item->conditionGroups->first()?->match_type === 'any' ? 'Any condition' : 'All conditions' }}
            ({{ $item->conditionGroups->first()?->conditions->count() ?? 0 }})
        @endif
    </div>

    <div class="flex gap-2 items-center justify-end pt-1">
        <x-button icon="chevron-up" title="Move up" class="cursor-pointer" wire:click="move({{ $item->id }}, 'up')"></x-button>
        <x-button icon="chevron-down" title="Move down" class="cursor-pointer" wire:click="move({{ $item->id }}, 'down')"></x-button>
        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('category-rules.edit', $item) }}"></x-button>
        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $item->id }})"></x-button>
    </div>
</div>
