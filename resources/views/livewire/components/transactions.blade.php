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
    public bool $allow_accounts;

    #[Locked]
    public bool $allow_running_balance;

    #[Session]
    public $only_uncategorized = false;

    #[Session]
    public ?int $account_id = null;
    public ?Account $account = null;

    #[Session]
    public ?int $original_category_id = null;
    public ?OriginalCategory $original_category = null;

    #[Session]
    public ?int $category_id = null;
    public ?Category $category = null;

    #[Session]
    public string $search = '';

    #[Session]
    public string $date_from = '';

    #[Session]
    public string $date_to = '';

    public $selected_transactions = [];
    public $bulk_category_id;

    public $chart_labels = [];
    public $chart_values = [];
    public $chart_colors = [];
    public $chart_tooltip_labels = [];
    public $chart_ids = [];
    public $chart_type = 'doughnut';

    public function mount(?Category $category = null, ?OriginalCategory $original_category = null, ?Account $account = null, ?bool $allow_accounts = false, bool $allow_running_balance = true): void
    {
        $this->allow_accounts = $allow_accounts;
        $this->allow_running_balance = $allow_running_balance;

        if ($account && $account->id) {
            $this->authorize('view', $account);
        }

        $this->account = $account;
        $this->account_id = $account?->id;

        $this->original_category = $original_category;
        $this->original_category_id = $original_category?->id;

        $this->category = $category;
        $this->category_id = $category?->id;

        $this->date_from = carbon()->startOfyear();
        $this->date_to = carbon()->now();

        $this->updateChartData();
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
        $query = Transaction::query();

        if ($this->account->id) {
            $this->authorize('view', $this->account);
            $query->where('account_id', $this->account->id);
        } else {
            // If no account is selected, only show transactions from accounts the user owns
            $query->whereIn('account_id', auth()->user()->accounts()->pluck('accounts.id'));
        }

        $query
            ->when($this->original_category_id ?? false, function ($query) {
                return $query->where('original_category_id', $this->original_category_id);
            })
            ->when($this->category_id ?? false, function ($query) {
                $category = Category::find($this->category_id);
                $category_id = $category->id;
                $descendants = collect($category->descendants)->pluck('id')->toArray();
                return $query->whereHas('categories', function ($query) use ($category_id, $descendants) {
                    $query
                        ->where('categories.id', $category_id)
                        ->orWhereIn('categories.id', $descendants);
                });
            })
            ->when($this->only_uncategorized ?? false, function ($query) {
                return $query->doesntHave('categories');
            })
            ->with('categories')
            ->with('originalCategory')
            ->whereBetween('transactions.created_at', [$this->date_from, $this->date_to]);

        if ($this->search) {
            $terms = $this->parseSearch($this->search);

            // Dynamically build the relevance selectRaw
            $bindings = [];
            $scoreParts = [];

            foreach ($terms['optional'] as $term) {
                foreach (['transactions.name', 'transactions.merchant_name', 'original_categories.name', 'original_categories.description'] as $field) {
                    $scoreParts[] = "CASE WHEN LOWER($field) LIKE ? THEN 1 ELSE 0 END";
                    $bindings[] = '%' . strtolower($term) . '%';
                }
            }

            if ($scoreParts) {
                $scoreExpr = implode(' + ', $scoreParts);
                $query
                    ->leftJoin('original_categories', 'transactions.original_category_id', '=', 'original_categories.id')
                    ->selectRaw("transactions.*, ($scoreExpr) as relevance", $bindings);
            } else {
                $query
                    ->selectRaw("transactions.*, 0 as relevance");
            }

            $query->where(function ($q) use ($terms) {
                $q->where(function ($q1) use ($terms) {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term) {
                            $q2->where('transactions.name', 'like', '%' . $term . '%')
                                ->orWhere('transactions.merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term) {
                            $q2->where('transactions.name', 'like', '%' . $term . '%')
                                ->orWhere('transactions.merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term) {
                        $q1->where('transactions.name', 'not like', '%' . $term . '%')
                            ->where(function ($q2) use ($term) {
                                $q2->where('transactions.merchant_name', 'not like', '%' . $term . '%')
                                    ->orWhereNull('transactions.merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('name', 'like', '%' . $term . '%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('description', 'like', '%' . $term . '%');
                            });
                    });
                }

            })
            ;
        } else {
            $query
                ->selectRaw("transactions.*, 0 as relevance");
        }

        return $query;
    }

    public function updating()
    {
        $this->resetPage();
    }

    public function updated()
    {
        $this->updateChartData();
    }

    public function rendered($view, $html)
    {
        $this->updateChartData();
    }

    public function with(): array
    {
        $query = $this->getTransactionsQuery();

        return [
            'transactions' => $query
                ->clone()
                ->with('account.linked_account')
                ->orderByRaw('relevance desc, transactions.created_at desc, transactions.transaction_type desc, transactions.id asc')
                //->ddRawSql()
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

    public function updateChartData() {
        $query = $this->getTransactionsQuery();

        $transactions = $query
            ->clone()
            ->reportable()
            ->with(['categories' => function($q) {
                $q->select('categories.id', 'categories.name', 'categories.color', 'categories.parent_id');
            }])
            ->get();

        $chart_data = [];
        $total_sum = 0;
        
        // Pre-fetch categories into a map for fast parent lookup
        $all_categories = Category::all()->keyBy('id');

        foreach ($transactions as $transaction) {
            $categories = $transaction->categories;

            if ($categories->isEmpty()) {
                $id = 0;
                $name = 'Uncategorized';
                $color = '#9ca3af';
                
                if (!isset($chart_data[$id])) {
                    $chart_data[$id] = ['id' => $id, 'label' => $name, 'color' => $color, 'total' => 0];
                }
                $chart_data[$id]['total'] += $transaction->amount;
                $total_sum += abs($transaction->amount);
                continue;
            }

            foreach ($categories as $category) {
                $current_filtered_id = $this->category_id ?: 0;
                $target = null;

                if ($current_filtered_id == 0) {
                    // Find top level ancestor
                    $target = $category;
                    while ($target && $target->parent_id != 0) {
                        $target = $all_categories->get($target->parent_id);
                    }
                } else {
                    // Is this category a descendant of the current filter?
                    $temp = $category;
                    $path = [];
                    while ($temp) {
                        $path[] = $temp->id;
                        if ($temp->id == $current_filtered_id) break;
                        $temp = $temp->parent_id ? $all_categories->get($temp->parent_id) : null;
                    }

                    if (!$temp || $temp->id != $current_filtered_id) {
                        continue; // Not under current filter
                    }

                    // Find the child of current_filtered_id in this path
                    if ($category->id == $current_filtered_id) {
                        $target = $category;
                    } else {
                        // The path is [leaf, ..., child_of_filter, filter]
                        // We want child_of_filter
                        $filter_index = array_search($current_filtered_id, $path);
                        if ($filter_index !== false && $filter_index > 0) {
                            $target = $all_categories->get($path[$filter_index - 1]);
                        } else {
                            $target = $category;
                        }
                    }
                }

                if ($target) {
                    if (!isset($chart_data[$target->id])) {
                        $chart_data[$target->id] = [
                            'id' => $target->id,
                            'label' => $target->name,
                            'color' => $target->color ?: '#3b82f6',
                            'total' => 0
                        ];
                    }
                    $chart_data[$target->id]['total'] += $transaction->amount;
                    $total_sum += abs($transaction->amount);
                }
                
                break; // Categorize by first category
            }
        }

        $chart_data = collect($chart_data)->values();

        $this->chart_ids = $chart_data->pluck('id')->toArray();
        $this->chart_labels = $chart_data->pluck('label')->toArray();
        $this->chart_values = $chart_data->pluck('total')->map(fn($v) => round(abs($v), 2))->toArray();
        $this->chart_colors = $chart_data->pluck('color')->toArray();
        
        $abs_total = $total_sum;
        $this->chart_tooltip_labels = $chart_data->map(function($item) use ($abs_total) {
            $val = abs($item['total']);
            $percent = $abs_total > 0 ? round(($val / $abs_total) * 100, 1) : 0;
            return currency($item['total'], flat: 1) . " ({$percent}%)";
        })->toArray();

        $this->dispatch('refresh-chart');
    }

    #[On('chart-clicked')]
    public function handleChartClick($categoryId)
    {
        if ($categoryId == 0) return; // Uncategorized or invalid
        
        $this->category_id = (int)$categoryId;
        $this->category = Category::find($this->category_id);
        $this->resetPage();
        // updateChartData will be triggered by rendered hook
    }

    public function goBack()
    {
        if ($this->category && $this->category->parent_id) {
            $this->category_id = $this->category->parent_id;
            $this->category = Category::find($this->category_id);
        } else {
            $this->category_id = 0;
            $this->category = null;
        }
        $this->resetPage();
    }

    #[On('save-category')]
    public function saveCategory($transaction_id, $category_id) {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([$category_id]);
        $transaction->save();
    }

    public function deleteTransactionCategory($category_transaction_id) {
        // We should check if the transaction belongs to the user
        $pivot = DB::table('category_transaction')->where('id', $category_transaction_id)->first();
        if ($pivot) {
            $transaction = Transaction::findOrFail($pivot->transaction_id);
            $this->authorize('update', $transaction);
            DB::table('category_transaction')->where('id', $category_transaction_id)->delete();
        }
    }

    public function deleteTransaction($transaction_id) {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('delete', $transaction);
        $transaction->categories()->detach();
        $transaction->delete();
    }

    public function bulkCategorize() {
        dd($this->selected_transactions, $this->bulk_category_id);
        foreach ($this->transactions as $transaction) {
            $transaction->categories()->sync([$this->category_id]);
        }
    }
}
?>
    <div x-data="{}" class="flex flex-col gap-4 items-start w-full">

        <div class="flex flex-col gap-2 w-full">
            <div class="flex items-center justify-between">
                <flux:heading size="xl" weight="semibold">
                    @if($category)
                        {{ $category->fullName }}
                    @else
                        All Transactions
                    @endif
                </flux:heading>

                @if($category_id)
                    <flux:button icon="chevron-left" size="sm" wire:click="goBack" variant="subtle">Back</flux:button>
                @endif
            </div>

            @if (!empty($chart_type) && count($chart_labels) > 0)
            <x-chart
                wire:key="chart-{{ $category_id ?: 'root' }}"
                class="w-full h-64"
                :type="$chart_type"
                :title="__('Category Breakdown')"
                clickEvent="chart-clicked"
                wire:ignore
            >
            </x-chart>
            @endif
        </div>

        <div class="flex flex-col md:flex-row gap-8 w-full items-start justify-between">
            <div class="flex flex-col gap-4 items-start grow p-0 md:pr-32">
                <!-- filters -->
                <div class="flex flex-col gap-4 items-start w-full">
                    <div class="flex gap-4 items-center w-full">
                        <label for="search">Search</label>
                        <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full" clearable></x-input>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <flux:field variant="inline">
                            <flux:checkbox wire:model.live.debounce="only_uncategorized" />
                            <flux:label>Only show transactions without categories</flux:label>
                        </flux:field>
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
                            @foreach(Category::all()->sortBy('fullName') as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->fullName }}</flux:select.option>
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
                            <x-table.td class="text-right">{!! currency(abs($total)) !!}</x-table.td>
                        </x-table.tr>
                    </x-slot>
                </x-table>
            </div>

        </div>

        <flux:separator variant="subtle"></flux:separator>

        <div class="flex w-full justify-between items-center">
            @if($transactions->hasPages())
                {{ $transactions->links(data: ['scrollTo' => '#transactions-table']) }}
            @else
                <div></div>
            @endif

            <div>
                <x-button wire:navigate href="{{ route('transactions.create', ['account' => $account_id]) }}">Add Transaction</x-button>
            </div>
        </div>

        <div class="flex flex-col gap-4 bg-white/10 p-4 rounded-xl w-full relative overflow-x-scroll">

            <x-table class="transactions-table min-w-full w-max" wire:scroll x-data="{selected_transactions: [] }">
                <x-slot name="head">
                    <x-table.tr x-show="selected_transactions.length > 0">
                        <x-table.td colspan="3">
                            <div class="flex gap-4">
                                <flux:select wire:model="bulk_category_id" >
                                    <flux:select.option value="0">-- No Category --</flux:select.option>
                                    @foreach(Category::all()->sortBy('fullName') as $category_option)
                                        <flux:select.option value="{{ $category_option->id }}">{{ $category_option->fullName }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button wire:click="bulkCategorize">Bulk Categorize</flux:button>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                    <x-table.tr wire:loading.remove>
                        <x-table.th class="text-center w-28"></x-table.th>
                        <x-table.th class="text-center w-28">Date</x-table.th>
                        @if ($allow_accounts)
                        <x-table.th class="2-56">Source</x-table.th>
                        @endif
                        <x-table.th>Description</x-table.th>
                        <x-table.th>Amount</x-table.th>
                        <x-table.th class="w-28"></x-table.th>
                    </x-table.tr>
                </x-slot>
                <x-slot name="body">
                    <x-table.tr wire:loading class="min-h-screen">
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
                        <x-table.td class="text-center">
                            <flux:checkbox wire:model="selected_transactions" x-model="selected_transactions" value="{{ $transaction['id'] }}" class="selected_transaction" />
                        </x-table.td>
                        <x-table.td class="text-center">
                            {{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }}
                            #{{ $transaction['id'] }}
                        </x-table.td>
                        @if ($allow_accounts)
                        <x-table.td class="max-w-lg">
                            <div>{{ $transaction['account']['name'] }}</div>
                            <div class="text-sm text-zinc-400">{{ $transaction['account']['linked_account']['provider_name'] }}</div>
                        </x-table.td>
                        @endif
                        <x-table.td class="max-w-lg">
                            <div>
                                {{ $transaction['name'] }}
                                <br>
                                @if($transaction['originalCategory'])
                                <small>{{ $transaction['originalCategory']['name'] }} - {{ $transaction['originalCategory']['details'] }}</small>
                                @endif
                                @if($transaction['payment_channel'])
                                <small>({{ $transaction['payment_channel'] }}) </small>
                                @endif

                                <div class="flex gap-2 items-center mt-2">

                                    @if($transaction['original']['manual'] ?? false)
                                    <div class="pointer-events-none text-xs p-1 h-auto relative rounded-lg shoadow-lg bg-green-800">
                                        <div class="p-0 text-nowrap text-shadow-lg">Manual</div>
                                    </div>
                                    @endif

                                    @foreach($transaction['categories'] as $category)
                                    <div class="cursor-pointer text-xs p-1 h-auto relative rounded-lg shoadow-lg" x-data="{ over: false }" @mouseout="over = false;" @mouseover="over = true;" style="background-color: {{ $category['color'] }}">
                                        <div @click="$dispatch('edit-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ htmlQuotes($transaction['name']) }}', transaction_amount: '{{ currency($transaction['amount'], $transaction['currency'], 1) }}', category_id: {{ $category['id'] }} })" class="p-0 text-nowrap text-shadow-lg">{{ $category['fullName'] }}</div>
                                        <flux:icon.x-mark variant="solid" x-cloak x-show="over" wire:confirm="Are you sure you want to delete this category? (#{{ $category['pivot']['id'] }})" wire:click="deleteTransactionCategory({{ $category['pivot']['id'] }})" class="absolute z-20 cursor-pointer -right-3 text-red-500 -top-3 size-6 p-px font-bold text-shadow-lg hover:bg-white rounded-full" />
                                    </div>
                                    @endforeach
                                    <flux:button size="xs" variant="subtle" inset @click="$dispatch('add-category', { transaction_id: {{ $transaction['id'] }}, transaction_name: '{{ htmlQuotes($transaction['name']) }}', transaction_amount: {{ $transaction['amount'] }} })" class="size-2" icon="plus"></flux:button>
                                </div>

                            </div>
                        </x-table.td>
                        <x-table.td class="text-right">
                            <div>{!! currency($transaction['amount'], $transaction['currency']) !!}</div>
                        @if ($transactions->first() && $transactions->first()['running_balance'] && $allow_running_balance && !$search)
                            <div class='{{ when($transaction['running_balance'] < 0, 'text-red-400', 'text-zinc-300') }} text-sm'>{!! currency($transaction['running_balance'], $transaction['currency'], 1) !!}</div>
                        @endif
                        </x-table.td>

                        <x-table.td class="text-right">
                            <div class="flex gap-2 items-center">
                                    <x-button icon="pencil" href="{{ route('transactions.edit', ['transaction' => $transaction['id'] ]) }}" />
                                @if($transaction['original']['manual'] ?? false)
                                    <flux:icon.trash wire:confirm="Are you sure you want to delete this transaction?\n(#{{ $transaction['id'] }} - {{ htmlQuotes($transaction['name']) }})" variant="solid" wire:click="deleteTransaction({{ $transaction['id'] }})" class="cursor-pointer text-red-500 size-6 p-px font-bold hover:bg-white rounded-full" />
                                @endif
                            </div>
                        </x-table.td>
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
                        <div x-show="add">Add Category</div>
                        <div x-show="!add">Edit Category</div>
                        <div class="flex justify-between">
                            <div><span x-html="transaction_name"></span> (#<span x-text="transaction_id"></span>)</div>
                            <span x-html="transaction_amount"></span>
                        </div>
                        <flux:select x-model="category_id" clearable>
                            <flux:select.option value="0">-- All Categories --</flux:select.option>
                            @foreach(Category::all()->sortBy('fullName') as $category_option)
                                <flux:select.option value="{{ $category_option->id }}">{{ $category_option->fullName }}</flux:select.option>
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
