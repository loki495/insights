<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\OriginalCategory;
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
        $categories = OriginalCategory::with('transactions')
            ->where('name', 'like', '%' . $this->search . '%')
            ->orWhere('description', 'like', '%' . $this->search . '%')
            ->orWhere('details', 'like', '%' . $this->search . '%')
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categories
        ];
    }

}

?>
    <x-page-wrapper heading="Categories" subheading="All Categories" :breadcrumbs="['Reports' => '', 'Categories' => route('reports.category.index') ]">

        <div class="flex flex-col gap-4">
            <!-- filters -->
            <div class="flex flex-col gap-4">
                <div class="flex gap-4 w-1/2 items-center">
                    <label for="search">Search</label>
                    <x-input type="text" wire:model.live="search" placeholder="Search" class="w-full"></x-input>
                </div>
            </div>
        </div>

        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>ID</x-table.th>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Details</x-table.th>
                    <x-table.th>Total</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
            @foreach($categories ?? [] as $category)
                <x-table.tr class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
                    <x-table.td class="text-left">{{ $category->plaid_id }}</x-table.td>
                    <x-table.td class="text-left">
                        <div>
                            {{ $category->name }}
                        </div>
                        <div class="text-xs italic">
                            {{ $category->description }}
                        </div>
                    </x-table.td>
                    <x-table.td class="text-left">{{ $category->details }}</x-table.td>
                    <x-table.td class="text-left">{!! currency($category->total) !!}</x-table.td>
                    <x-table.td class="text-left">
                        <x-button icon="list-bullet" title="View Transactions" class="cursor-pointer" href="{{ route('reports.category.index', $category) }}" wire:navigate></x-button>
                    </x-table.td>
                </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>

    </x-page-wrapper>
