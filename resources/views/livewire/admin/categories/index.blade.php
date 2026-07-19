<?php

declare(strict_types=1);

use App\Models\Category;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    #[Session]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    private function flatTree(int $parent_id, int|float $depth = 0): array
    {
        $result = [];

        $categories = Category::query()
            ->with('children')
            ->with('parent')
            ->where('parent_id', '=', $parent_id)
            ->orderBy('name')
            ->get();

        foreach ($categories as $category) {
            $category->depth = $depth;
            $category->has_children = count($category->children) > 0;

            $result[] = $category;
            $result = array_merge($result, $this->flatTree($category->id, $depth + 1));
        }

        return $result;
    }

    public function delete(Category $category): void
    {
        $this->authorize('delete', $category);
        $category->delete();
    }

    public function with(): array
    {
        if ($this->search !== '' && $this->search !== '0') {
            $categories = Category::query()
                ->with('children')
                ->with('parent')
                ->where(function ($query): void {
                    $query
                        ->where('id', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                })
                ->orderBy('name')
                ->get();
        } else {
            $categories = collect($this->flatTree(0));
        }

        return [
            'categories' => $categories,
        ];
    }
}

?>
    <x-page-wrapper heading="Categories" subheading="All Categories" :breadcrumbs="['Reports' => '', 'Categories' => route('categories.index') ]">

        <div class="flex flex-col sm:flex-row gap-4 sm:justify-between w-full">
            <!-- filters -->
            <div class="flex flex-col gap-4 w-full sm:w-max">
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                    <label for="search">Search</label>
                    <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full"></x-input>
                </div>
            </div>

            <div class="flex flex-col gap-4 w-full sm:w-auto">
                <flux:button wire:navigate href="{{ route('categories.create') }}" class="w-full sm:w-auto">Create Category</flux:button>
            </div>
        </div>


        <div class="categories-table w-full" x-data="{ open: {} }">
            <x-responsive-table
                :items="$categories ?? []"
                row-view="livewire.admin.categories.partials.category-table-row"
                card-view="livewire.admin.categories.partials.category-card"
                empty-message="No categories found"
                :context="['search' => $search]"
            >
                <x-slot name="head">
                    <x-table.tr>
                        <x-table.th class="w-8">ID</x-table.th>
                        <x-table.th class="w-48">Parent</x-table.th>
                        <x-table.th class="w-48">Name</x-table.th>
                        <x-table.th class="w-48">Description</x-table.th>
                        <x-table.th>Color</x-table.th>
                        <x-table.th></x-table.th>
                    </x-table.tr>
                </x-slot>
            </x-responsive-table>
        </div>

    </x-page-wrapper>

    <script>
    function closeCat(cat_id) {
        let data = Alpine.closestDataStack(document.querySelector('.categories-table'))[0];
        let child_data = document.querySelector('.row-category-' + cat_id)._x_dataStack[0].children

        if (data.open[cat_id]) {
            for (var index in child_data) {
                closeCat(child_data[index])
            }
        }

        data.open[cat_id] = false
    }

    function toggleCat(cat_id) {

        let data = Alpine.closestDataStack(document.querySelector('.categories-table'))[0];

        if (data.open[cat_id]) {
            let child_data = document.querySelector('.row-category-' + cat_id)._x_dataStack[0].children

            for (var index in child_data) {
                closeCat(child_data[index])
            }
        }

        data.open[cat_id] = !data.open[cat_id]
    }
    </script>
