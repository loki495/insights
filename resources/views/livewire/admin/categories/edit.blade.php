<?php

use App\Models\Category;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?Category $category;

    public $category_id;

    public $parent_id;

    public $name;

    public $description;

    public $color;

    public function mount(?Category $category): void
    {
        if ($category && $category->exists) {
            $this->authorize('update', $category);
        } else {
            $this->authorize('create', Category::class);
        }

        $this->category = $category;
        $this->category_id = $category->id;
        $this->parent_id = $category->parent_id;
        $this->name = $category->name;
        $this->description = $category->description;
        $this->color = $category->color;
    }

    public function save(): void
    {
        if ($this->category && $this->category->exists) {
            $this->authorize('update', $this->category);
        } else {
            $this->authorize('create', Category::class);
        }

        $this->category = Category::updateOrCreate([
            'id' => $this->category_id,
        ], [
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'parent_id' => $this->parent_id ?: 0,
        ]);

        $this->redirectRoute('categories.index');
    }
}

?>
    <x-page-wrapper :heading="$category->id > 0 ? 'Edit Category' : 'Create Category'" :subheading="$category->id > 0 ? $category->fullName : ''" :breadcrumbs="['Categories' => 'categories.index']">

        <div class="mb-4 w-full max-w-xl flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Parent</label>
                <flux:select wire:model="parent_id" clearable>
                    <option value="">None</option>
                    @foreach(Category::all()->sortBy('name') as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->fullName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Name</label>
                <flux:input wire:model="name" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Description</label>
                <flux:textarea wire:model="description" />
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Color</label>
                <div class="w-8 h-8"><input type="color" wire:model="color" class="w-full h-full" /></div>
            </div>

            <x-button wire:click="save" class="w-full sm:w-auto">Save</x-button>
        </div>


    </x-page-wrapper>
