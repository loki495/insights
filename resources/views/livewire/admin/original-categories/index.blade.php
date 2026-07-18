<?php

declare(strict_types=1);

use App\Models\OriginalCategory;
use Livewire\Volt\Component;

new class extends Component
{
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', OriginalCategory::class);
        $this->search = '';
    }

    public function with(): array
    {
        $userId = auth()->id();
        $categories = OriginalCategory::query()
            ->withSum(['transactions' => function ($query) use ($userId) {
                $query->whereIn('account_id', function ($q) use ($userId) {
                    $q->select('accounts.id')
                        ->from('accounts')
                        ->join('linked_accounts', 'accounts.linked_account_id', '=', 'linked_accounts.id')
                        ->where('linked_accounts.user_id', $userId);
                });
            }], 'amount')
            ->where(function ($query) {
                $query->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('pf_primary', 'like', '%'.$this->search.'%')
                    ->orWhere('pf_detailed', 'like', '%'.$this->search.'%');
            })
            ->orderBy('name')
            ->get();

        return [
            'categories' => $categories,
        ];
    }
}

?>
    <x-page-wrapper heading="Categories" subheading="All Categories" :breadcrumbs="['Reports' => '', 'Categories' => route('reports.category.index') ]">

        <div class="flex flex-col gap-4 justify-between">
            <div class="flex flex-col gap-4 w-full sm:w-1/2">
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                    <label for="search">Search</label>
                    <x-input type="text" wire:model.live="search" placeholder="Search" class="w-full"></x-input>
                </div>
            </div>
        </div>

        <x-responsive-table
            :items="$categories ?? []"
            row-view="livewire.admin.original-categories.partials.original-category-table-row"
            card-view="livewire.admin.original-categories.partials.original-category-card"
            empty-message="No categories found"
        >
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>ID</x-table.th>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Details</x-table.th>
                    <x-table.th>Total</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
        </x-responsive-table>

    </x-page-wrapper>
