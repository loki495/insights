<?php


declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Category;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    public string $search = '';

    public function mount(): void
    {
        $this->search = '';
    }

    public function with(): array
    {
        $categories = Category::query()
            ->with('children')
            ->with('parent')
            ->with('transactions')
            ->where('parent_id', '=', 0)
            ->when($this->search, function (Builder $query) {
                $query
                    ->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%');
            })
            ->orderBy('name')
            ->get();

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

        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Parent</x-table.th>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Description</x-table.th>
                    <x-table.th>Color</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
            @foreach($categories ?? [] as $category)
                <x-table.tr class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
                    <x-table.td class="text-left">
                        @if($category->parent)
                        <div class="text-center cursor-pointer text-xs p-1 h-auto relative rounded-lg shoadow-lg" x-data="{ over: false }" @mouseout="over = false;" @mouseover="over = true;" style="background-color: {{ $category->parent->color }}">
                            {{ $category->parent->name }}
                        </div>
                        @endif
                    </x-table.td>
                    <x-table.td class="text-left">{{ $category->name }} {{ $category->parent?->name }}</x-table.td>
                    <x-table.td class="text-left">{{ $category->description }}</x-table.td>
                    <x-table.td class="text-left"><div class="flex gap-4 justify-center items-center">{{ $category->color}} <div class="w-4 h-4" style="background-color: {{ $category->color }}"></div></div></x-table.td>
                    <x-table.td class="text-left">
                        <x-button icon="pencil" title="Edit" class="cursor-pointer" wire:navigate href="{{ route('categories.edit', $category) }}"></x-button>
                        <x-button icon="trash" title="Delete" class="cursor-pointer" variant="danger" wire:click="delete({{ $category->id }})"></x-button>
                    </x-table.td>
                </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>

    </x-page-wrapper>
