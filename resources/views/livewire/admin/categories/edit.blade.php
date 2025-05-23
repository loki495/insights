<?php

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public ?Category $category;

    public $name;
    public $description;
    public $color;

    public function mount(?Category $category): void
    {
        $this->category = $category;
        $this->category_id = $category->id;
        $this->name = $category->name;
        $this->description = $category->description;
        $this->color = $category->color;
    }

    public function save() {
        $this->category->name = $this->name;
        $this->category->description = $this->description;
        $this->category->color = $this->color;
        $this->category->save();

        $this->redirectRoute('categories.index');
    }
}

?>
    <x-page-wrapper :heading="$category->id > 0 ? 'Edit ' . $category->name : 'Create Category'" :breadcrumbs="['Categories' => 'categories.index']">

        <div class="mb-4 max-w-[400px]">
            <x-table>
                <x-slot name="body">
                    <x-table.tr>
                        <x-table.th class="text-left">Name</x-table.th>
                        <x-table.td><flux:input wire:model="name" /></x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Description</x-table.th>
                        <x-table.td><flux:textarea wire:model="description" /></x-table.td>
                    </x-table.tr>
                    <x-table.tr>
                        <x-table.th class="text-left">Color</x-table.th>
                        <x-table.td><div class="w-8 h-8"><input type="color" wire:model="color" class="w-full h-full" /></div></x-table.td>
                    </x-table.tr>
                </x-slot>
            </x-table>

            <x-button wire:click="save" class="mt-4">Save</x-button>
        </div>


    </x-page-wrapper>
