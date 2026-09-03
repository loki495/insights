<x-table.tr class="border-b border-zinc-300 dark:border-zinc-700" wire:key="rule-{{ $item->id }}">
    <x-table.td>
        <div class="flex items-center gap-1">
            <x-button icon="chevron-up" title="Move up" class="cursor-pointer" wire:click="move({{ $item->id }}, 'up')"></x-button>
            <x-button icon="chevron-down" title="Move down" class="cursor-pointer" wire:click="move({{ $item->id }}, 'down')"></x-button>
        </div>
    </x-table.td>

    <x-table.td class="text-left">{{ $item->name ?: '(unnamed rule)' }}</x-table.td>

    <x-table.td class="text-left">
        <div class="text-xs px-2 py-1 rounded inline-block" style="background-color: {{ $item->category->color }}">
            {{ $item->category->fullName }}
        </div>
    </x-table.td>

    <x-table.td class="text-left">
        @if($item->conditionGroups->count() > 1)
            {{ $item->match_type === 'any' ? 'Any group' : 'All groups' }} ({{ $item->conditionGroups->count() }} groups)
        @else
            {{ $item->conditionGroups->first()?->match_type === 'any' ? 'Any condition' : 'All conditions' }}
            ({{ $item->conditionGroups->first()?->conditions->count() ?? 0 }})
        @endif
    </x-table.td>

    <x-table.td>
        <flux:switch wire:click="toggleActive({{ $item->id }})" :checked="$item->active"></flux:switch>
    </x-table.td>

    <x-table.td class="text-left">
        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('category-rules.edit', $item) }}"></x-button>
        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $item->id }})"></x-button>
    </x-table.td>
</x-table.tr>
