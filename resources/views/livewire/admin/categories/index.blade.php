<?php



declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Category;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    #[Session]
    public string $search = '';

    private function flatTree(int $parent_id, $depth = 0): array
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

    public function with(): array
    {
        if ($this->search) {
            $categories = Category::query()
                ->with('children')
                ->with('parent')
                ->where(function ($query) {
                    $query
                        ->where('id', 'like', '%' . $this->search . '%')
                        ->orWhere('name', 'like', '%' . $this->search . '%')
                        ->orWhere('description', 'like', '%' . $this->search . '%');
                })
                ->orderBy('name')
                ->get();
        } else {
            $categories = collect($this->flatTree(0));
        }

        return [
            'categories' => $categories
        ];
    }

}

?>
    <x-page-wrapper heading="Categories" subheading="All Categories" :breadcrumbs="['Reports' => '', 'Categories' => route('categories.index') ]">

        <div class="flex gap-4 justify-between">
            <!-- filters -->
            <div class="flex flex-col gap-4 w-max">
                <div class="flex gap-4 items-center">
                    <label for="search">Search</label>
                    <x-input type="text" wire:model.live="search" placeholder="Search" class="w-full"></x-input>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="flex gap-4 w-1/2 items-center">
                    <flux:button wire:navigate href="{{ route('categories.create') }}">Create Category</flux:button>
                </div>
            </div>
        </div>


        <x-table x-data="{ open: {} }" class="categories-table">
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
            <x-slot name="body">
                {{--
                <x-table.tr>
                    <x-table.td colspan="6">
                        <template x-for="x,i in open" :key="i">
                            <span x-show="x === true" x-text="i"></span>
                        </template>
                    </x-table.td>
                </x-table.tr>
                --}}
                @foreach($categories ?? [] as $category)
                    @php
                        $childIds = $category->children->pluck("id")->toArray();
                        $isRoot = $category->parent_id === null || $category->parent_id === 0;
                    @endphp

                <x-table.tr
                    class="row-category-{{ $category->id }} border-b border-zinc-300 dark:border-zinc-700 transition-opacity duration-300 ease-in"
                    x-bind:class="{ 'hover:bg-zinc-100/10 dark:hover:bg-zinc-900/20 bg-zinc-400 dark:bg-zinc-400/10 cursor-pointer': {{ !$this->search && $category->has_children ? 'true' : 'false' }} }"
                    x-ref="cat-{{ $category->id }}"
                    x-data="{
                        children: {{ json_encode($childIds) }},
                        cat_id: '{{ $category->id }}',
                        parent_cat_id: '{{ $category->parent_id }}',
                        is_root: {{ $isRoot ? 'true' : 'false' }}
                    }"
                    x-show="open[parent_cat_id] === true || is_root || {{ $this->search ? 'true' : 'false' }}"
                    style="padding-left: {{ $this->search ? 0 : $category->depth * 20 }}px;"
                    @click="toggleCat(cat_id)"
                    x-cloak
                >
                    <x-table.td>{{ $category->id }}</x-table.td>
                    {{-- Parent Column --}}
                    <x-table.td>
                        <div class="flex gap-2">
                            @if($category->parent)
                            <div class="text-xs px-2 py-1 rounded" style="background-color: {{ $category->parent->color }}">
                                {{ $category->parent->fullName }}
                            </div>
                            @endif
                        </div>
                    </x-table.td>

                    {{-- Name --}}
                    <x-table.td class="text-left">
                        {{ $category->name }}
                    </x-table.td>

                    {{-- Description --}}
                    <x-table.td class="text-left">
                        <div class="flex justify-between">
                            <div>{{ $category->description }}</div>
                    </x-table.td>

                    {{-- Color --}}
                    <x-table.td class="text-left">
                        <div class="flex gap-4 justify-center items-center">
                            {{ $category->color }}
                            <div class="w-4 h-4" style="background-color: {{ $category->color }}"></div>
                        </div>
                    </x-table.td>

                    {{-- Actions --}}
                    <x-table.td class="text-left">
                        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('categories.edit', $category) }}"></x-button>
                        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $category->id }})"></x-button>
                    </x-table.td>
                </x-table.tr>
    @endforeach
            </x-slot>
        </x-table>

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
