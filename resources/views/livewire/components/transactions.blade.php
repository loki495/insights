<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\OriginalCategory;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    #[Locked]
    public $allow_accounts = true;

    #[Session]
    public ?int $account_id;
    public ?Account $account;

    #[Session]
    public ?int $original_category_id;
    public ?OriginalCategory $original_category;

    #[Session]
    public ?int $category_id;
    public ?Category $category;

    #[Session]
    public string $search = '';

    #[Session]
    public string $date_from = '';

    #[Session]
    public string $date_to = '';

    public $chart_labels = [];
    public $chart_values = [];
    public $chart_tooltip_labels = [];
    public $chart_ids = [];
    public $chart_type = 'doughnut';

    public function mount(?Category $category, ?OriginalCategory $original_category, ?Account $account, ?bool $allow_accounts = false): void
    {
        $this->allow_accounts = $allow_accounts;
        $this->account = $account;
        $this->account_id = $account->id;

        $this->original_category = $original_category;
        $this->original_category_id = $original_category->id;

        $this->category = $category;
        $this->category_id = $category->id;

        $this->date_from = carbon()->startOfyear();
        $this->date_to = carbon()->now();
    }

    private function parseSearch(string $query): array
    {
        preg_match_all('/([+-]?)"([^"]+)"|([+-]?)(\S+)/', $query, $matches, PREG_SET_ORDER);

        $parsed = [
            'required' => [],
            'excluded' => [],
            'optional' => [],
        ];

        foreach ($matches as $match) {
            $prefix = $match[1] ?: $match[3];
            $term = $match[2] ?: $match[4];

            switch ($prefix) {
                case '+':
                    $parsed['required'][] = $term;
                    break;
                case '-':
                    $parsed['excluded'][] = $term;
                    break;
                default:
                    $parsed['optional'][] = $term;
                    break;
            }
        }

        return $parsed;
    }

    public function getTransactionsQuery(): Builder
    {
        $query = Transaction::query()
            ->when($this->original_category_id ?? false, function ($query) {
                return $query->where('original_category_id', $this->original_category_id);
            })
            ->when($this->category_id ?? false, function ($query) {
                return $query->whereHas('categories', function ($q) {
                    $q->where('categories.id', $this->category_id);
                });
            })
            ->with('originalCategory')
            ->with('categories')
            ->whereBetween('created_at', [$this->date_from, $this->date_to]);

        if ($this->account->id) {
            $query->where('account_id', $this->account->id);
        }

        if ($this->search) {
            $terms = $this->parseSearch($this->search);
            $query->where(function ($q) use ($terms) {
                $q->where(function ($q1) use ($terms) {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term) {
                            $q2->where('name', 'like', '%' . $term . '%')
                                ->orWhere('merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term) {
                            $q2->where('name', 'like', '%' . $term . '%')
                                ->orWhere('merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term) {
                        $q1->where('name', 'not like', '%' . $term . '%')
                            ->where(function ($q2) use ($term) {
                                $q2->where('merchant_name', 'not like', '%' . $term . '%')
                                    ->orWhereNull('merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('name', 'like', '%' . $term . '%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('description', 'like', '%' . $term . '%');
                            });
                    });
                }

            });
        }

        return $query;
    }

    public function updating()
    {
        $this->resetPage();
    }

    public function with(): array
    {
        //$query = $this->updateChartData();
        $query = $this->getTransactionsQuery();

        return [
            'transactions' => $query
                ->clone()
                ->with('account.linked_account')
                ->orderBy('created_at', 'desc')
                ->paginate(25),
            'count' => $query->count(),
            'total' => $query->sum('amount'),
        ];
    }

    public function updatedAccountId($value = null)
    {
        $this->account = Account::find($value);
        $this->dispatch('accountIdChanged', accountId: $value);
    }

    public function updatedCategoryId($value = null)
    {
        $this->original_category = OriginalCategory::find($value);
        $this->dispatch('categoryIdChanged', categoryId: $value);
    }

    #[Computed]
    public function accounts() {
        $accounts = Account::with('linked_account')->get()->sortBy(function ($account) {
            return $account->linked_account->provider_name . ' - ' . $account->name;
        });
        return $accounts;
    }

    public function updateChartData(): Builder {
        $query = $this->getTransactionsQuery();

        $chart_data = $query
            ->clone()
            //->select('original_category_id', DB::raw('SUM(amount) as total'))
            //->groupBy('original_category_id')
            ->get()
            ->map(function ($item) {
                //dd($item);
                //$cat = OriginalCategory::find($item->original_category_id);
                //return [
                    //'id' => $item->original_category_id,
                    //'label' => $cat->details,
                    //'value' => $item->total
                //];
            });

        $this->chart_ids = $chart_data->pluck('id')->toArray();
        $this->chart_labels = $chart_data->pluck('label')->toArray();
        $this->chart_values = $chart_data->pluck('value')->toArray();
        $this->chart_tooltip_labels = $chart_data->pluck('value')->map(fn($value) => currency($value, flat: 1))->toArray();
        $this->chart_type = 'doughnut';

        $this->dispatch('refresh-chart');

        return $query;
    }

    #[On('save-category')]
    public function saveCategory($transaction_id, $category_id) {
        $transaction = Transaction::find($transaction_id);
        $transaction->categories()->syncWithoutDetaching([$category_id]);
        $transaction->save();
    }

    public function deleteTransactionCategory($category_transaction_id) {
        DB::table('category_transaction')->where('id', $category_transaction_id)->delete();
    }
}
?>
    <div x-data="{}" class="flex flex-col gap-4 items-start w-full">

        @if (!empty($chart_type) && $chart_labels != '[]' && $chart_values != '[]')
        <x-chart
            class="w-full h-64"
            :type="$chart_type"
            :tooltip_labels="$chart_tooltip_labels"
            :title="__('Total')"
            :labels="$chart_labels"
            :values="$chart_values"
            :dataIDs="$chart_ids"
            clickEvent="clicked"
            wire:ignore
        >
        </x-chart>
        @endif

        <div class="flex flex-col md:flex-row gap-8 w-full items-start justify-between">
            <div class="flex flex-col gap-4 items-start grow p-0 md:pr-32">
                <!-- filters -->
                <div class="flex flex-col gap-4 items-start w-full">
                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Search</label>
                        <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full" clearable></x-input>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Original Category</label>
                        <flux:select wire:model.live="original_category_id" clearable>
                            <flux:select.option value="0">-- All Original Categories --</flux:select.option>
                            @foreach(OriginalCategory::all()->sortBy('details') as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->details }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Category</label>
                        <flux:select wire:model.live="category_id" clearable>
                            <flux:select.option value="0">-- All Categories --</flux:select.option>
                            @foreach(Category::all()->sortBy('name') as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <label for="date">Date</label>
                        <x-input type="datetime-local" wire:model.live="date_from" placeholder="From" class="w-full"></x-input>
                        <x-input type="datetime-local" wire:model.live="date_to" placeholder="To" class="w-full"></x-input>
                    </div>
                </div>

                @if ($allow_accounts)
                <div class="flex gap-4 items-center w-full">
                    <label for="account">Account</label>
                    <flux:select wire:model.live="account_id" class="w-full">
                        <flux:select.option value="0">-- All Accounts --</flux:select.option>
                        @foreach($this->accounts as $account_option)
                        <flux:select.option value="{{ $account_option->id }}">{{ $account_option->linked_account->provider_name }} - {{ $account_option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @endif

            </div>

            <div class="h-full p-2 rounded-xl bg-white/10">
                <x-table>
                    <x-slot name="body">
                        <x-table.tr>
                            <x-table.td class="!px-0 align-top">From:</x-table.td>
                            <x-table.td class="!px-3">
                                <span class="font-bold">{!! \Carbon\Carbon::parse($date_from)->format('l jS, M Y') !!}</span>
                                <br>
                                at {!! \Carbon\Carbon::parse($date_from)->format('h:ia') !!}
                            </x-table.td>
                        </x-table.tr>
                        <x-table.tr>
                            <x-table.td class="!px-0 align-top">To:</x-table.td>
                            <x-table.td class="!px-3">
                                <span class="font-bold">{!! \Carbon\Carbon::parse($date_to)->format('l jS, M Y') !!}</span>
                                <br>
                                at {!! \Carbon\Carbon::parse($date_to)->format('h:ia') !!}
                            </x-table.td>
                        </x-table.tr>
                    </x-slot>
                </x-table>
                <x-table>
                    <x-slot name="body">
                        <x-table.tr>
                            <x-table.td class="!px-0">Transactions:</x-table.td>
                            <x-table.td class="text-right">{{ $count }}</x-table.td>
                        </x-table.tr>
                        <x-table.tr>
                            <x-table.td class="!px-0">Total:</x-table.td>
                            <x-table.td class="text-right">{!! currency($total) !!}</x-table.td>
                        </x-table.tr>
                    </x-slot>
                </x-table>
            </div>

        </div>

        <flux:separator variant="subtle"></flux:separator>

        @if($transactions->hasPages())
        {{ $transactions->links(data: ['scrollTo' => '#transactions-table']) }}
        @endif

        <div class="flex flex-col gap-4 bg-white/10 p-4 rounded-xl w-full relative overflow-x-scroll">

            <x-table class="transactions-table min-h-screen min-w-full w-max" wire:scroll>
                <x-slot name="head">
                    <x-table.tr wire:loading.remove>
                        <x-table.th class="text-center w-28">Date</x-table.th>
                        <x-table.th class="2-56">Source</x-table.th>
                        <x-table.th>Description</x-table.th>
                        <x-table.th>Amount</x-table.th>
                        @if ($transactions->first()['running_balance'])
                        <x-table.th>Running Balance</x-table.th>
                        @endif
                    </x-table.tr>
                </x-slot>
                <x-slot name="body">
                    <x-table.tr wire:loading>
                        <x-table.td colspan="9">
                           <div class="absolute w-full h-full flex items-start justify-center z-10">
                                <div class="mt-16 sticky left-1/2 top-32 -translate-x-1/2">
                                    <flux:icon.loading class="w-16 h-16" />
                                </div>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @forelse($transactions ?? [] as $transaction)
                    <x-table.tr wire:loading.remove class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
                        <x-table.td class="text-center">{{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }}</x-table.td>
                        <x-table.td class="max-w-lg">
                            <div>{{ $transaction['account']['name'] }}</div>
                            <div class="text-sm text-zinc-400">{{ $transaction['account']['linked_account']['provider_name'] }}</div>
                        </x-table.td>
                        <x-table.td class="max-w-lg">
                            <div>
                                {{ $transaction['name'] }}
                                <br>
                                <small>{{ $transaction['originalCategory']['name'] }} - {{ $transaction['originalCategory']['details'] }}</small>
                                <small>({{ $transaction['payment_channel'] }}) </small>

                                <div class="flex gap-2 items-center mt-2">
                                    @foreach($transaction['categories'] as $category)
                                    <div class="cursor-pointer text-xs p-1 h-auto relative rounded-lg shoadow-lg" x-data="{ over: false }" @mouseout="over = false;" @mouseover="over = true;" style="background-color: {{ $category['color'] }}">
                                        <div @click="$dispatch('edit-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ $transaction['name'] }}', transaction_amount: '{{ currency($transaction['amount'], $transaction['currency']) }}', category_id: {{ $category['id'] }} })" class="p-0 text-nowrap text-shadow-lg">{{ $category['name'] }}</div>
                                        <flux:icon.x-mark variant="solid" x-cloak x-show="over" wire:confirm="Are you sure you want to delete this category? (#{{ $category['pivot']['id'] }})" wire:click="deleteTransactionCategory({{ $category['pivot']['id'] }})" class="absolute z-20 cursor-pointer -right-3 text-red-500 -top-3 size-6 p-px font-bold text-shadow-lg hover:bg-white rounded-full" />
                                    </div>
                                    @endforeach
                                    <flux:button size="xs" variant="subtle" inset @click="$dispatch('add-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ $transaction['name'] }}', transaction_amount: {{ $transaction['amount'] }} })" class="size-2" icon="plus"></flux:button>
                                </div>

                            </div>
                        </x-table.td>
                        <x-table.td class="text-right">{!! currency($transaction['amount'], $transaction['currency']) !!}</x-table.td>
                        @if ($transactions->first()['running_balance'])
                        <x-table.td class="text-right">{!! currency($transaction['running_balance'], $transaction['currency']) !!}</x-table.td>
                        @endif
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
        {{ $transactions->links(data: ['scrollTo' => '#transactions-table']) }}
        @endif

        <template x-teleport="body">
            <div class="fixed inset-0 flex items-center justify-center z-50" x-cloak x-data="{open: false, add: false, transaction_id: 0, category_id: 0, transaction_name: '', transaction_amount: 0 }" x-on:keydown.escape.window="open = false" x-show="open" @add-category.window="add=true;open = true;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;" @edit-category.window="add=false;open = true;transaction_id = event.detail.transaction_id;category_id=event.detail.category_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;">
                <div class="fixed inset-0 bg-zinc-900/50" @click="open = false"></div>
                <div class="bg-zinc-900 text-white p-4 rounded-xl w-96 z-10">
                    <div class="flex flex-col gap-4">
                        <div class="flex justify-between">
                            <div><span x-text="transaction_name"></span> (#<span x-text="transaction_id"></span>)</div>
                            <span x-html="transaction_amount"></span>
                        </div>
                        <div x-show="add">Add Category</div>
                        <div x-show="!add">Edit Category</div>
                        <flux:select x-model="category_id" clearable>
                            <flux:select.option value="0">-- All Categories --</flux:select.option>
                            @foreach(Category::all()->sortBy('name') as $category_option)
                                <flux:select.option value="{{ $category_option->id }}">{{ $category_option->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button x-bind:disabled="!category_id" x-on:click="open = false;$dispatch('save-category', { transaction_id: transaction_id, category_id: category_id })" class="cursor-pointer">Save</flux:button>
                    </div>
                </div>
            </div>
        </template>
    </div>

@script
    <script>
    document.addEventListener('livewire:load', function () {

    })
    </script>
@endscript
