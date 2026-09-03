<?php

declare(strict_types=1);

use App\Actions\DecorateCategoryColorsForUserAction;
use App\Actions\Models\CategoryRule\DeleteCategoryRule;
use App\Models\CategoryRule;
use Livewire\Volt\Component;

new class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', CategoryRule::class);
    }

    public function toggleActive(CategoryRule $categoryRule): void
    {
        $this->authorize('update', $categoryRule);
        $categoryRule->update(['active' => ! $categoryRule->active]);
    }

    /**
     * Swaps this rule's priority with whichever of its own rules sits immediately
     * before/after it — the simplest possible reorder UI, no drag-and-drop dependency.
     */
    public function move(CategoryRule $categoryRule, string $direction): void
    {
        $this->authorize('update', $categoryRule);

        $neighbor = auth()->user()->categoryRules()
            ->where('priority', $direction === 'up' ? '<' : '>', $categoryRule->priority)
            ->orderBy('priority', $direction === 'up' ? 'desc' : 'asc')
            ->first();

        if (! $neighbor) {
            return;
        }

        [$categoryRule->priority, $neighbor->priority] = [$neighbor->priority, $categoryRule->priority];
        $categoryRule->save();
        $neighbor->save();
    }

    public function delete(CategoryRule $categoryRule): void
    {
        $this->authorize('delete', $categoryRule);
        DeleteCategoryRule::run($categoryRule);
    }

    public function with(): array
    {
        $rules = auth()->user()->categoryRules()
            ->with(['category', 'conditionGroups.conditions'])
            ->orderBy('priority')
            ->get();

        DecorateCategoryColorsForUserAction::run(auth()->user(), $rules->pluck('category'));

        return [
            'rules' => $rules,
        ];
    }
}

?>
<x-page-wrapper heading="Autocategorize Rules" subheading="Automatically assign a category to new transactions" :breadcrumbs="['Autocategorize Rules' => route('category-rules.index')]">

    <div class="flex flex-col sm:flex-row gap-4 sm:justify-end w-full">
        <flux:button wire:navigate href="{{ route('category-rules.create') }}" class="w-full sm:w-auto">Create Rule</flux:button>
    </div>

    <x-responsive-table
        :items="$rules"
        row-view="livewire.admin.category-rules.partials.category-rule-table-row"
        card-view="livewire.admin.category-rules.partials.category-rule-card"
        empty-message="No rules yet — new transactions won't be auto-categorized until you create one."
    >
        <x-slot name="head">
            <x-table.tr>
                <x-table.th class="w-8">Priority</x-table.th>
                <x-table.th>Name</x-table.th>
                <x-table.th>Category</x-table.th>
                <x-table.th>Match</x-table.th>
                <x-table.th>Active</x-table.th>
                <x-table.th></x-table.th>
            </x-table.tr>
        </x-slot>
    </x-responsive-table>

</x-page-wrapper>
