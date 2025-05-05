<?php

use App\Models\OriginalCategory;
use App\Models\Transaction;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public ?int $category_id;
    public ?OriginalCategory $category;

    public string $search = '';

    public string $date_from = '';

    public string $date_to = '';


    public function mount($category_id = null): void
    {
        $this->category_id = $category_id;
        $this->category = OriginalCategory::find($category_id);
        $this->search = '';
        $this->date_from = carbon('-1 month')->startOfMonth()->toDateTimeLocalString();
        $this->date_to = carbon()->now();
    }

    public function with(): array
    {
        $query = Transaction::query()
            ->when($this->category_id, function ($query) {
                return $query->where('original_category_id', $this->category_id);
            })
            ->with('originalCategory')
            ->whereBetween('created_at', [$this->date_from, $this->date_to])
            ->where(function($query) {
                $query
                    ->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('merchant_name', 'like', '%' . $this->search . '%')
                    ->orWhereRelation('originalCategory', 'name', 'like', '%' . $this->search . '%')
                    ->orWhereRelation('originalCategory', 'description', 'like', '%' . $this->search . '%');
            });

       return [
            'transactions' => $query
                ->clone()
                ->orderBy('created_at', 'desc')
                ->paginate(25),
            'count' => $query->count(),
            'total' => $query->sum('amount'),
        ];
    }

    public function updatedCategoryId($value = null)
    {
        $this->category = OriginalCategory::find($value);
        $this->dispatch('categoryIdChanged', categoryId: $value);
    }
}

?>
    <x-page-wrapper heading="Reports" subheading="Category Transactions - {{ $category_id ? $category->name : 'All Categories' }} {{ $category_id ? '(' . $category->plaid_id . ')': '' }})" :breadcrumbs="['Reports' => 'reports.index', 'Categories' => 'reports.category.index']">

        <div class="flex flex-col md:flex-row gap-8 w-full items-start justify-between">
            <div class="flex flex-col gap-4 items-start grow p-0 md:pr-32">
                <!-- filters -->
                <div class="flex flex-col gap-4 items-start w-full">
                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Search</label>
                        <x-input type="text" wire:model.live="search" placeholder="Search" class="w-full" clearable></x-input>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Category</label>
                        <flux:select wire:model.live="category_id" clearable>
                            <flux:select.option value="0">-- All Categories --</flux:select.option>
                            @foreach(OriginalCategory::all()->sortBy('details') as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->details }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <label for="date">Date</label>
                        <x-input type="datetime-local" wire:model.live="date_from" placeholder="From" class="w-full"></x-input>
                        <x-input type="datetime-local" wire:model.live="date_to" placeholder="To" class="w-full"></x-input>
                    </div>
                </div>
            </div>

            <div class="w-max h-full py-2 rounded-xl bg-white/10">
                <x-table>
                    <x-slot name="body">
                        <x-table.tr>
                            <x-table.td>Transactions:</x-table.td>
                            <x-table.td class="text-right">{{ $count }}</x-table.td>
                        </x-table.tr>
                        <x-table.tr>
                            <x-table.td>Total:</x-table.td>
                            <x-table.td>{!! currency($total) !!}</x-table.td>
                        </x-table.tr>
                    </x-slot>
                </x-table>
            </div>
        </div>

        <flux:separator variant="subtle"></flux:separator>

        @if($transactions->hasPages())
        {{ $transactions->links() }}
        @endif
        <div class="flex flex-col gap-4 bg-white/10 p-4 rounded-xl">
            <x-table>
                <x-slot name="head">
                    <x-table.tr>
                        <x-table.th class="text-center w-8">Date</x-table.th>
                        <x-table.th>Source</x-table.th>
                        <x-table.th>Amount</x-table.th>
                        <x-table.th></x-table.th>
                    </x-table.tr>
                </x-slot>
                <x-slot name="body">
                    <x-table.tr wire:loading.class="table-row" wire:loading.remove.class="hidden" class="hidden">
                        <x-table.td colspan="4" class="w-full">
                            <div class="flex items-center justify-center mt-4 w-full">
                                <flux:icon.loading />
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @forelse($transactions ?? [] as $transaction)
                    <x-table.tr wire:loading.remove class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
                        <x-table.td class="text-center">{{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }}</x-table.td>
                        <x-table.td class="max-w-lg">
                            <div class="text-sm flex items-center gap-4">
                                <div class="w-8">
                                    <img title="{{ $transaction['originalCategory']['name'] }}" alt="{{ $transaction['originalCategory']['name'] }}" class="object-contain max-w-full invert-85" src="{{ $transaction['originalCategory']['logo_url'] }}">
                                </div>
                                <div>
                                    {{ $transaction['name'] }}
                                    <br>
                                    <small>{{ $transaction['originalCategory']['name'] }} - {{ $transaction['originalCategory']['details'] }}</small>
                                    <small>({{ $transaction['payment_channel'] }}) </small>
                                </div>
                            </div>
                        </x-table.td>
                        <x-table.td class="text-right">{!! currency($transaction['amount'], $transaction['currency']) !!}</x-table.td>
                    </x-table.tr>
                    @empty
                    <x-table.tr wire:loading.remove>
                        <x-table.td colspan="4" class="text-center"><div class="mt-4">No transactions found</div></x-table.td>
                    </x-table.tr>
                    @endforelse
                </x-slot>
            </x-table>
        </div>

        @if($transactions)
        {{ $transactions->links() }}
        @endif

    </x-page-wrapper>
      <script type="text/javascript">

    // waiting for DOM loaded
    document.addEventListener('DOMContentLoaded', function () {

      // listen for the event
      Livewire.on('categoryIdChanged', params => {
        if (!params.categoryId) {
            params.categoryId = '';
        }
        history.pushState(null, null, '{{ route('reports.category.index') }}/' + params.categoryId);
      });
    });
  </script>
