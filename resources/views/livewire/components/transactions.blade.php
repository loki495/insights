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

new class extends Component
{
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

        if ($this->account?->id) {
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
                $descendants = $category->descendants;

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
                foreach (['transactions.name', 'transactions.merchant_name', 'original_categories.name', 'original_categories.pf_detailed'] as $field) {
                    $scoreParts[] = "CASE WHEN LOWER($field) LIKE ? THEN 1 ELSE 0 END";
                    $bindings[] = '%'.strtolower($term).'%';
                }
            }

            if ($scoreParts) {
                $scoreExpr = implode(' + ', $scoreParts);
                $query
                    ->leftJoin('original_categories', 'transactions.original_category_id', '=', 'original_categories.id')
                    ->selectRaw("transactions.*, ($scoreExpr) as relevance", $bindings);
            } else {
                $query
                    ->selectRaw('transactions.*, 0 as relevance');
            }

            $query->where(function ($q) use ($terms) {
                $q->where(function ($q1) use ($terms) {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term) {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term) {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term) {
                        $q1->where('transactions.name', 'not like', '%'.$term.'%')
                            ->where(function ($q2) use ($term) {
                                $q2->where('transactions.merchant_name', 'not like', '%'.$term.'%')
                                    ->orWhereNull('transactions.merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('name', 'like', '%'.$term.'%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('pf_detailed', 'like', '%'.$term.'%');
                            });
                    });
                }

            });
        } else {
            $query
                ->selectRaw('transactions.*, 0 as relevance');
        }

        return $query;
    }

    public function updating()
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = $this->getTransactionsQuery();

        $this->updateChartData();

        $transactions = $query
            ->clone()
            ->with('account.linked_account')
            ->orderByRaw('relevance desc, transactions.created_at desc, transactions.transaction_type desc, transactions.id asc')
            // ->ddRawSql()
            ->paginate(25);

        return [
            'transactions' => $transactions,
            'count' => $query->count(),
            'total' => $query->sum('amount'),
            'merchantSuggestions' => $this->merchantSuggestions($transactions->getCollection()),
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
    public function accounts()
    {
        $accounts = Account::with('linked_account')->get()->sortBy(function ($account) {
            return $account->linked_account->provider_name.' - '.$account->name;
        });

        return $accounts;
    }

    #[Computed]
    public function categories()
    {
        return Category::all()->sortBy('fullName')->values();
    }

    #[Computed]
    public function categoryPickerOptions(): array
    {
        return $this->categories
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->fullName,
                'color' => $category->color ?: '#3b82f6',
            ])
            ->values()
            ->toArray();
    }

    #[Computed]
    public function categoryPickerLookup(): array
    {
        return $this->categories
            ->mapWithKeys(fn ($category) => [
                $category->id => [
                    'id' => $category->id,
                    'name' => $category->fullName,
                    'color' => $category->color ?: '#3b82f6',
                ],
            ])
            ->toArray();
    }

    /**
     * For each distinct merchant on the current page, find the category most
     * commonly used on other transactions from that merchant. Doubles as the
     * groundwork for a future auto-categorization rule engine.
     */
    private function merchantSuggestions($transactions): array
    {
        $merchants = collect($transactions)
            ->pluck('merchant_name')
            ->filter()
            ->unique()
            ->values();

        if ($merchants->isEmpty()) {
            return [];
        }

        return Transaction::query()
            ->whereIn('merchant_name', $merchants)
            ->whereHas('categories')
            ->with('categories')
            ->get()
            ->groupBy('merchant_name')
            ->map(function ($merchantTransactions) {
                $topCategoryId = $merchantTransactions
                    ->flatMap->categories
                    ->countBy('id')
                    ->sortDesc()
                    ->keys()
                    ->first();

                if (! $topCategoryId) {
                    return null;
                }

                $category = $this->categories->firstWhere('id', $topCategoryId);

                return $category ? [
                    'id' => $category->id,
                    'name' => $category->fullName,
                    'color' => $category->color ?: '#3b82f6',
                ] : null;
            })
            ->filter()
            ->toArray();
    }

    public function updateChartData()
    {
        $query = $this->getTransactionsQuery();

        $transactions = $query
            ->clone()
            ->reportable()
            ->with(['categories' => function ($q) {
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

                if (! isset($chart_data[$id])) {
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
                        if ($temp->id == $current_filtered_id) {
                            break;
                        }
                        $temp = $temp->parent_id ? $all_categories->get($temp->parent_id) : null;
                    }

                    if (! $temp || $temp->id != $current_filtered_id) {
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
                    if (! isset($chart_data[$target->id])) {
                        $chart_data[$target->id] = [
                            'id' => $target->id,
                            'label' => $target->name,
                            'color' => $target->color ?: '#3b82f6',
                            'total' => 0,
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
        $this->chart_values = $chart_data->pluck('total')->map(fn ($v) => round(abs($v), 2))->toArray();
        $this->chart_colors = $chart_data->pluck('color')->toArray();

        $abs_total = $total_sum;
        $this->chart_tooltip_labels = $chart_data->map(function ($item) use ($abs_total) {
            $val = abs($item['total']);
            $percent = $abs_total > 0 ? round(($val / $abs_total) * 100, 1) : 0;

            return currency($item['total'], flat: 1)." ({$percent}%)";
        })->toArray();

        $this->dispatch('refresh-chart');
    }

    #[On('transactions-updated')]
    public function refreshTransactions(): void
    {
        $this->resetPage();
    }

    #[On('chart-clicked')]
    public function handleChartClick($categoryId)
    {
        if ($categoryId == 0) {
            return;
        } // Uncategorized or invalid

        $this->category_id = (int) $categoryId;
        $this->category = Category::find($this->category_id);
        $this->resetPage();
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

    public function saveCategory($transaction_id, $category_id)
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([$category_id]);
        $transaction->save();
    }

    public function createCategory(string $name, ?int $parent_id, ?string $color): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }

        $category = Category::create([
            'name' => $name,
            'parent_id' => $parent_id ?: 0,
            'color' => $color ?: '#3b82f6',
        ]);

        return [
            'id' => $category->id,
            'name' => $category->fullName,
            'color' => $category->color,
        ];
    }

    public function deleteTransactionCategory($category_transaction_id)
    {
        // We should check if the transaction belongs to the user
        $pivot = DB::table('category_transaction')->where('id', $category_transaction_id)->first();
        if ($pivot) {
            $transaction = Transaction::findOrFail($pivot->transaction_id);
            $this->authorize('update', $transaction);
            DB::table('category_transaction')->where('id', $category_transaction_id)->delete();
        }
    }

    public function deleteTransaction($transaction_id)
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('delete', $transaction);
        $transaction->categories()->detach();
        $transaction->delete();
    }

    public function bulkCategorize()
    {
        dd($this->selected_transactions, $this->bulk_category_id);
        foreach ($this->transactions as $transaction) {
            $transaction->categories()->sync([$this->category_id]);
        }
    }
}
?>
    <div
        x-data="{
            optimisticCategories: {},
            categoryList: @js($this->categoryPickerOptions),
            categoryLookup: @js($this->categoryPickerLookup),
            categoryColorPalette: ['#3b82f6', '#ef4444', '#22c55e', '#f97316', '#a855f7', '#ec4899', '#14b8a6', '#eab308'],
            applyCategory(transactionId, categoryId) {
                const category = this.categoryLookup[categoryId];
                if (!category) return;

                this.optimisticCategories[transactionId] = category;

                $wire.saveCategory(transactionId, categoryId)
                    .then(() => { delete this.optimisticCategories[transactionId]; })
                    .catch(() => { delete this.optimisticCategories[transactionId]; });
            },
        }"
        class="flex flex-col gap-4 items-start w-full"
    >

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
                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                        <label for="search">Search</label>
                        <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full" clearable></x-input>
                    </div>

                    <div class="flex gap-4 items-center w-full">
                        <flux:field variant="inline">
                            <flux:checkbox wire:model.live.debounce="only_uncategorized" />
                            <flux:label>Only show transactions without categories</flux:label>
                        </flux:field>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                        <label for="search">Original Category</label>
                        <flux:select wire:model.live="original_category_id" clearable>
                            <flux:select.option value="0">-- All Original Categories --</flux:select.option>
                            @foreach(OriginalCategory::all()->sortBy('full_path') as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->full_path }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                        <label for="search">Category</label>
                        <flux:select wire:model.live="category_id" clearable>
                            <flux:select.option value="0">-- All Categories --</flux:select.option>
                            @foreach($this->categories as $category_option)
                            <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->fullName }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
                        <label for="date">Date</label>
                        <x-input type="datetime-local" wire:model.live="date_from" placeholder="From" class="w-full"></x-input>
                        <x-input type="datetime-local" wire:model.live="date_to" placeholder="To" class="w-full"></x-input>
                    </div>
                </div>

                @if ($allow_accounts)
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
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

        <div class="flex flex-col-reverse sm:flex-row w-full justify-between items-stretch sm:items-center gap-3">
            @if($transactions->hasPages())
                {{ $transactions->links(data: ['scrollTo' => '#transactions-table']) }}
            @else
                <div></div>
            @endif

            <div class="w-full sm:w-auto">
                <x-button wire:navigate href="{{ route('transactions.create', ['account' => $account_id]) }}" class="w-full sm:w-auto">Add Transaction</x-button>
            </div>
        </div>

        <div class="flex flex-col gap-3 sm:hidden w-full">
            @forelse($transactions ?? [] as $transaction)
            <div wire:key="mobile-txn-{{ $transaction['id'] }}" class="flex flex-col gap-2 p-3 rounded-xl bg-white/10">
                <div class="flex items-start justify-between gap-2">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }} #{{ $transaction['id'] }}
                    </div>
                    <div class="flex gap-2 items-center shrink-0">
                        <x-button icon="pencil" href="{{ route('transactions.edit', ['transaction' => $transaction['id'] ]) }}" />
                        @if($transaction['original']['manual'] ?? false)
                        <flux:icon.trash wire:confirm="Are you sure you want to delete this transaction?\n(#{{ $transaction['id'] }} - {{ htmlQuotes($transaction['name']) }})" variant="solid" wire:click="deleteTransaction({{ $transaction['id'] }})" class="cursor-pointer text-red-500 size-6 p-px font-bold hover:bg-white rounded-full" />
                        @endif
                    </div>
                </div>

                @if ($allow_accounts)
                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $transaction['account']['name'] }} &middot; {{ $transaction['account']['linked_account']['provider_name'] }}
                </div>
                @endif

                <div>
                    <div class="font-medium break-words">{{ $transaction['name'] }}</div>
                    @if($transaction['originalCategory'])
                    <small class="text-zinc-500 dark:text-zinc-400">{{ $transaction['originalCategory']['full_path'] }}</small>
                    @endif
                    @if($transaction['payment_channel'])
                    <small class="text-zinc-500 dark:text-zinc-400">({{ $transaction['payment_channel'] }})</small>
                    @endif
                </div>

                @include('livewire.components.partials.transaction-category-chips', ['transaction' => $transaction])

                <div class="flex items-end justify-between gap-2 pt-1 border-t border-zinc-700/30">
                    <div class="font-semibold">{!! currency($transaction['amount'], $transaction['currency']) !!}</div>
                    @if ($transactions->first() && $transactions->first()['running_balance'] && $allow_running_balance && !$search)
                    <div class="{{ when($transaction['running_balance'] < 0, 'text-red-400', 'text-zinc-500 dark:text-zinc-400') }} text-sm">
                        Balance: {!! currency($transaction['running_balance'], $transaction['currency'], 1) !!}
                    </div>
                    @endif
                </div>
            </div>
            @empty
            <div class="text-center text-zinc-500 dark:text-zinc-400 py-4">No transactions found</div>
            @endforelse
        </div>

        <div class="hidden sm:flex flex-col gap-4 bg-white/10 p-4 rounded-xl w-full relative overflow-x-scroll">

            <x-table class="transactions-table min-w-full w-max" wire:scroll x-data="{selected_transactions: [] }">
                <x-slot name="head">
                    <x-table.tr x-show="selected_transactions.length > 0">
                        <x-table.td colspan="3">
                            <div class="flex gap-4">
                                <flux:select wire:model="bulk_category_id" >
                                    <flux:select.option value="0">-- No Category --</flux:select.option>
                                    @foreach($this->categories as $category_option)
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
                                <small>{{ $transaction['originalCategory']['full_path'] }}</small>
                                @endif
                                @if($transaction['payment_channel'])
                                <small>({{ $transaction['payment_channel'] }}) </small>
                                @endif

                                <div class="mt-2">
                                    @include('livewire.components.partials.transaction-category-chips', ['transaction' => $transaction])
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
            <div
                class="fixed inset-0 flex items-center justify-center z-50 p-4"
                x-cloak
                x-data="{
                    open: false,
                    add: false,
                    transaction_id: 0,
                    transaction_name: '',
                    transaction_amount: 0,
                    suggested_category_id: 0,
                    categorySearch: '',
                    creatingCategory: false,
                    newCategoryName: '',
                    newCategoryParentId: '',
                    newCategoryColor: '#3b82f6',
                    creatingCategoryError: '',
                    filteredCategories() {
                        const q = this.categorySearch.trim().toLowerCase();
                        if (!q) return this.categoryList;
                        return this.categoryList.filter(c => c.name.toLowerCase().includes(q));
                    },
                    startCreatingCategory() {
                        this.creatingCategory = true;
                        this.creatingCategoryError = '';
                        this.newCategoryName = this.categorySearch.trim();
                        this.newCategoryParentId = '';
                        this.newCategoryColor = this.categoryColorPalette[Math.floor(Math.random() * this.categoryColorPalette.length)];
                    },
                    createAndApplyCategory() {
                        if (!this.newCategoryName.trim()) {
                            this.creatingCategoryError = 'Name is required.';
                            return;
                        }
                        this.creatingCategoryError = '';
                        $wire.createCategory(this.newCategoryName, this.newCategoryParentId || null, this.newCategoryColor).then((created) => {
                            this.categoryList.push(created);
                            this.categoryLookup[created.id] = created;
                            this.open = false;
                            this.creatingCategory = false;
                            this.applyCategory(this.transaction_id, created.id);
                        }).catch(() => {
                            this.creatingCategoryError = 'Could not create category. Please try again.';
                        });
                    },
                }"
                x-on:keydown.escape.window="open = false"
                x-show="open"
                @add-category.window="add=true;open=true;categorySearch='';creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;suggested_category_id=event.detail.suggested_category_id;"
                @edit-category.window="add=false;open=true;categorySearch='';creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;suggested_category_id=0;"
            >
                <div class="fixed inset-0 bg-zinc-900/50" @click="open = false"></div>
                <div class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white p-4 rounded-xl w-full max-w-96 max-h-[85vh] z-10 flex flex-col overflow-hidden shadow-xl">
                    <div class="flex flex-col gap-4 min-h-0 flex-1" x-show="!creatingCategory">
                        <div x-show="add">Add Category</div>
                        <div x-show="!add">Edit Category</div>
                        <div class="flex justify-between">
                            <div><span x-html="transaction_name"></span> (#<span x-text="transaction_id"></span>)</div>
                            <span x-html="transaction_amount"></span>
                        </div>

                        <button
                            type="button"
                            x-show="suggested_category_id && categoryLookup[suggested_category_id]"
                            @click="open = false; applyCategory(transaction_id, suggested_category_id)"
                            class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed flex items-center gap-2 shrink-0"
                            :style="`border-color: ${categoryLookup[suggested_category_id]?.color}; color: ${categoryLookup[suggested_category_id]?.color}`"
                        >
                            <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${categoryLookup[suggested_category_id]?.color}`"></span>
                            Suggested: <span x-text="categoryLookup[suggested_category_id]?.name"></span>
                        </button>

                        <x-input type="text" x-model="categorySearch" placeholder="Search categories..." class="w-full shrink-0"></x-input>

                        <div class="overflow-y-auto flex flex-col gap-1 min-h-0 flex-1">
                            <template x-for="cat in filteredCategories()" :key="cat.id">
                                <button
                                    type="button"
                                    @click="open = false; applyCategory(transaction_id, cat.id)"
                                    class="cursor-pointer text-left px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                >
                                    <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${cat.color}`"></span>
                                    <span x-text="cat.name"></span>
                                </button>
                            </template>
                            <div x-show="filteredCategories().length === 0" class="text-zinc-500 dark:text-zinc-400 text-sm px-2 py-1.5">No matching categories</div>
                        </div>

                        <button
                            type="button"
                            @click="startCreatingCategory()"
                            class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed border-zinc-400 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-white/10 shrink-0"
                        >+ Create new category</button>
                    </div>

                    <div class="flex flex-col gap-4 min-h-0 flex-1" x-show="creatingCategory" x-cloak>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="creatingCategory = false" class="cursor-pointer text-zinc-500 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white">&larr;</button>
                            <div>Create Category</div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="new-category-name" class="text-sm text-zinc-600 dark:text-zinc-400">Name</label>
                            <x-input id="new-category-name" type="text" x-model="newCategoryName" placeholder="e.g. Groceries" class="w-full" autofocus></x-input>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="new-category-parent" class="text-sm text-zinc-600 dark:text-zinc-400">Parent (optional)</label>
                            <select id="new-category-parent" x-model="newCategoryParentId" class="border border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 rounded-lg p-2 w-full">
                                <option value="">-- No parent (top-level) --</option>
                                <template x-for="cat in categoryList" :key="cat.id">
                                    <option :value="cat.id" x-text="cat.name"></option>
                                </template>
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <label for="new-category-color" class="text-sm text-zinc-600 dark:text-zinc-400">Color</label>
                            <input id="new-category-color" type="color" x-model="newCategoryColor" class="h-8 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600" />
                        </div>

                        <div x-show="creatingCategoryError" x-text="creatingCategoryError" class="text-sm text-red-500"></div>

                        <flux:button variant="primary" @click="createAndApplyCategory()">Create &amp; Assign</flux:button>
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
