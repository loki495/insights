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
    public array $account_ids = [];

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

    protected bool $chartNeedsRefresh = false;

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

        $ownedAccountIds = auth()->user()->accounts()->pluck('accounts.id');

        if ($this->account?->id) {
            $this->authorize('view', $this->account);
            $query->where('account_id', $this->account->id);
        } elseif (! empty($this->account_ids)) {
            // Only show transactions from selected accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds->intersect($this->account_ids)->values());
        } else {
            // If no account is selected, only show transactions from accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds);
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

    /**
     * The chart aggregates the full (unpaginated) date-range query, so it
     * doesn't need recomputing on a page-only navigation (nextPage/
     * previousPage/gotoPage bypass this hook entirely, since they set
     * $this->paginators directly rather than syncing a client property).
     * selected_transactions is excluded since toggling checkboxes doesn't
     * change what the chart should show.
     */
    public function updated($name): void
    {
        if ($name === 'selected_transactions') {
            return;
        }

        $this->chartNeedsRefresh = true;
    }

    public function with(): array
    {
        $query = $this->getTransactionsQuery();

        if ($this->chartNeedsRefresh) {
            $this->updateChartData();
            $this->chartNeedsRefresh = false;
        }

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
                'name' => $category->name,
                'full_name' => $category->fullName,
                'parent_id' => $category->parent_id ?: 0,
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
                    'name' => $category->name,
                    'full_name' => $category->fullName,
                    'parent_id' => $category->parent_id ?: 0,
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
        $this->chartNeedsRefresh = true;
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
        $this->chartNeedsRefresh = true;
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
        $this->chartNeedsRefresh = true;
        $this->resetPage();
    }

    public function saveCategory($transaction_id, $category_id)
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([$category_id]);
        $transaction->save();
        $this->chartNeedsRefresh = true;
    }

    /**
     * Up to two best-guess categories for a single transaction, used to seed
     * the category picker: the category most commonly assigned to other
     * transactions from the same merchant, and separately the category most
     * commonly assigned to other transactions sharing the same Plaid
     * original category (catches cases merchant_name is missing or too
     * inconsistent to match on, since Plaid's own categorization is usually
     * present). Already-assigned categories are excluded.
     */
    public function suggestCategoriesForTransaction($transaction_id): array
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('view', $transaction);

        $currentCategoryIds = $transaction->categories->pluck('id');

        return collect([
            $this->topCategoryFor(fn ($query) => $query->where('merchant_name', $transaction->merchant_name), $transaction, (bool) $transaction->merchant_name),
            $this->topCategoryFor(fn ($query) => $query->where('original_category_id', $transaction->original_category_id), $transaction, (bool) $transaction->original_category_id),
        ])
            ->filter()
            ->unique('id')
            ->reject(fn ($suggestion) => $currentCategoryIds->contains($suggestion['id']))
            ->take(2)
            ->values()
            ->toArray();
    }

    private function topCategoryFor(Closure $scope, Transaction $transaction, bool $enabled): ?array
    {
        if (! $enabled) {
            return null;
        }

        $query = Transaction::query()->where('id', '!=', $transaction->id);
        $scope($query);

        $topCategoryId = $query
            ->whereHas('categories')
            ->with('categories')
            ->get()
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
            'name' => $category->name,
            'full_name' => $category->fullName,
            'parent_id' => $category->parent_id ?: 0,
            'color' => $category->color,
        ];
    }

    public function clearCategory($transaction_id): void
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('update', $transaction);
        $transaction->categories()->sync([]);
        $this->chartNeedsRefresh = true;
    }

    public function deleteTransaction($transaction_id)
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('delete', $transaction);
        $transaction->categories()->detach();
        $transaction->delete();
        $this->chartNeedsRefresh = true;
    }

    public function bulkAssignCategory($category_id): void
    {
        $transactions = Transaction::whereIn('id', $this->selected_transactions)->get();

        foreach ($transactions as $transaction) {
            $this->authorize('update', $transaction);
            $transaction->categories()->sync([$category_id]);
        }

        $this->selected_transactions = [];
        $this->chartNeedsRefresh = true;
    }

    public function bulkDeleteTransactions(): void
    {
        // Only manually-added transactions can be deleted (matches the
        // single-delete action); Plaid-synced ones are silently skipped.
        $transactions = Transaction::whereIn('id', $this->selected_transactions)
            ->get()
            ->filter(fn (Transaction $transaction) => $transaction->original['manual'] ?? false);

        foreach ($transactions as $transaction) {
            $this->authorize('delete', $transaction);
            $transaction->categories()->detach();
            $transaction->delete();
        }

        $this->selected_transactions = [];
        $this->chartNeedsRefresh = true;
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
            clearCategory(transactionId) {
                $wire.clearCategory(transactionId);
            },
            selectMode: false,
            selected_transactions: [],
            toggleSelectMode() {
                this.selectMode = !this.selectMode;
                this.selected_transactions = [];
            },
            bulkAssignCategory(categoryId) {
                $wire.bulkAssignCategory(categoryId).then(() => {
                    this.selected_transactions = [];
                    this.selectMode = false;
                });
            },
            bulkDeleteTransactions() {
                $wire.bulkDeleteTransactions().then(() => {
                    this.selected_transactions = [];
                    this.selectMode = false;
                });
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
                class="w-full"
                :type="$chart_type"
                :title="__('Category Breakdown')"
                clickEvent="chart-clicked"
                wire:ignore
            >
            </x-chart>
            @endif
        </div>

        <div class="flex flex-col lg:flex-row gap-8 w-full items-start justify-between">
            <div class="flex flex-col gap-4 items-start grow min-w-0 w-full p-0 lg:pr-8 xl:pr-32">
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
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full" x-data="{ accountsOpen: false }">
                    <label for="account">Account</label>
                    <div class="relative w-full" @click.outside="accountsOpen = false">
                        <button
                            type="button"
                            @click="accountsOpen = !accountsOpen"
                            class="cursor-pointer flex items-center justify-between w-full px-3 py-2 rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-left"
                        >
                            <span>
                                @if(empty($account_ids))
                                    -- All Accounts --
                                @elseif(count($account_ids) === 1)
                                    @php $selectedAccount = $this->accounts->firstWhere('id', $account_ids[0]); @endphp
                                    {{ $selectedAccount ? $selectedAccount->linked_account->provider_name.' - '.$selectedAccount->name : '1 account selected' }}
                                @else
                                    {{ count($account_ids) }} accounts selected
                                @endif
                            </span>
                            <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-500" />
                        </button>

                        <div
                            x-show="accountsOpen"
                            x-cloak
                            class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 shadow-lg p-2 flex flex-col gap-1"
                        >
                            <button
                                type="button"
                                wire:click="$set('account_ids', [])"
                                class="cursor-pointer text-left px-2 py-1.5 rounded-lg text-sm text-blue-600 dark:text-blue-400 hover:bg-zinc-100 dark:hover:bg-white/10"
                            >Clear (All Accounts)</button>

                            @foreach($this->accounts as $account_option)
                            <flux:checkbox
                                wire:model.live="account_ids"
                                value="{{ $account_option->id }}"
                                label="{{ $account_option->linked_account->provider_name }} - {{ $account_option->name }}"
                            />
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

            </div>

            <details class="w-full lg:hidden shrink-0 rounded-xl bg-zinc-100 dark:bg-white/10">
                <summary class="cursor-pointer select-none p-2 font-medium">Details</summary>
                <div class="p-2 pt-0">
                    @include('livewire.components.partials.transaction-summary-details')
                </div>
            </details>

            <div class="hidden lg:block w-full lg:w-auto shrink-0 rounded-xl bg-zinc-100 dark:bg-white/10 p-2">
                @include('livewire.components.partials.transaction-summary-details')
            </div>

        </div>

        <flux:separator variant="subtle"></flux:separator>

        <div class="flex flex-col-reverse sm:flex-row w-full justify-between items-stretch sm:items-center gap-3">
            @if($transactions->hasPages())
                {{ $transactions->links(data: ['scrollTo' => false]) }}
            @else
                <div></div>
            @endif

            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <flux:button x-show="!selectMode" variant="subtle" icon="check-circle" class="w-full sm:w-auto cursor-pointer" @click="toggleSelectMode()">Select</flux:button>
                <flux:button x-show="selectMode" x-cloak variant="primary" icon="x-mark" class="w-full sm:w-auto cursor-pointer" @click="toggleSelectMode()">Cancel Select</flux:button>
                <flux:button
                    x-show="selectMode"
                    x-cloak
                    variant="subtle"
                    class="w-full sm:w-auto cursor-pointer"
                    @click="selected_transactions.length === {{ $transactions->count() }} ? (selected_transactions = []) : (selected_transactions = @js($transactions->pluck('id')))"
                >
                    <span x-text="selected_transactions.length === {{ $transactions->count() }} && selected_transactions.length > 0 ? 'Deselect All' : 'Select All'"></span>
                </flux:button>
                <x-button wire:navigate href="{{ route('transactions.create', ['account' => $account?->id ?: (count($account_ids) === 1 ? $account_ids[0] : null)]) }}" class="w-full sm:w-auto">Add Transaction</x-button>
            </div>
        </div>

        <div
            class="w-full flex flex-col sm:flex-row sm:items-center gap-3 p-3 rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-950/40"
            x-show="selectMode && selected_transactions.length > 0"
            x-cloak
        >
            <div class="flex items-center gap-2 text-sm font-medium text-blue-900 dark:text-blue-100">
                <span class="inline-flex items-center justify-center size-6 shrink-0 rounded-full bg-blue-600 text-white text-xs font-semibold" x-text="selected_transactions.length"></span>
                selected
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:ml-auto">
                <flux:button size="sm" variant="primary" class="cursor-pointer" @click="$dispatch('bulk-add-category')">Assign Category</flux:button>
                <flux:button size="sm" variant="danger" class="cursor-pointer" wire:confirm="Delete the selected transactions? Only manually-added transactions can be deleted — synced ones will be skipped." @click="bulkDeleteTransactions()">Delete Selected</flux:button>
                <button type="button" class="cursor-pointer text-sm text-blue-900 dark:text-blue-100 underline self-start sm:self-auto" @click="selected_transactions = []">Clear selection</button>
            </div>
        </div>

        @php
            $showRunningBalance = $transactions->first() && $transactions->first()['running_balance'] && $allow_running_balance && !$search;
        @endphp

        <x-responsive-table
            :items="$transactions ?? []"
            row-view="livewire.components.partials.transaction-table-row"
            card-view="livewire.components.partials.transaction-card"
            empty-message="No transactions found"
            :context="['allow_accounts' => $allow_accounts, 'showRunningBalance' => $showRunningBalance]"
            loading-target="search,only_uncategorized,original_category_id,category_id,date_from,date_to,account_ids,page,nextPage,previousPage,gotoPage"
            class="transactions-table min-w-full w-max"
            wire:scroll
        >
            <x-slot name="head">
                <x-table.tr wire:loading.remove>
                    <x-table.th class="text-center w-28" x-show="selectMode"></x-table.th>
                    <x-table.th class="text-center w-28">Date</x-table.th>
                    @if ($allow_accounts)
                    <x-table.th class="2-56">Source</x-table.th>
                    @endif
                    <x-table.th>Description</x-table.th>
                    <x-table.th>Amount</x-table.th>
                    <x-table.th class="w-28"></x-table.th>
                </x-table.tr>
            </x-slot>
        </x-responsive-table>

        @if($transactions)
        <div class="w-full">
            {{ $transactions->links(data: ['scrollTo' => false]) }}
        </div>
        @endif

        <template x-teleport="body">
            <div
                class="fixed inset-0 flex items-center justify-center z-50 p-4"
                x-cloak
                x-data="{
                    open: false,
                    add: false,
                    bulkMode: false,
                    transaction_id: 0,
                    transaction_name: '',
                    transaction_amount: 0,
                    editing_category_id: 0,
                    suggestions: [],
                    suggestionsLoading: false,
                    categorySearch: '',
                    categoryParentId: 0,
                    creatingCategory: false,
                    newCategoryName: '',
                    newCategoryParentId: '',
                    newCategoryColor: '#3b82f6',
                    creatingCategoryError: '',
                    selectCategory(categoryId) {
                        this.open = false;
                        if (this.bulkMode) {
                            this.bulkAssignCategory(categoryId);
                        } else {
                            this.applyCategory(this.transaction_id, categoryId);
                        }
                    },
                    loadSuggestions(transactionId) {
                        this.suggestions = [];
                        this.suggestionsLoading = true;
                        $wire.suggestCategoriesForTransaction(transactionId).then((list) => {
                            if (this.transaction_id === transactionId) {
                                this.suggestions = list;
                                this.suggestionsLoading = false;
                            }
                        });
                    },
                    filteredCategories() {
                        const q = this.categorySearch.trim().toLowerCase();
                        if (!q) return this.categoryList;
                        return this.categoryList
                            .filter(c => c.full_name.toLowerCase().includes(q))
                            .sort((a, b) => a.full_name.localeCompare(b.full_name));
                    },
                    categoriesAtCurrentLevel() {
                        return this.categoryList
                            .filter(c => (c.parent_id || 0) === this.categoryParentId)
                            .sort((a, b) => a.name.localeCompare(b.name));
                    },
                    categoryHasChildren(catId) {
                        return this.categoryList.some(c => (c.parent_id || 0) === catId);
                    },
                    currentParentCategory() {
                        return this.categoryLookup[this.categoryParentId] || null;
                    },
                    drillInto(catId) {
                        this.categoryParentId = catId;
                        this.categorySearch = '';
                    },
                    drillUp() {
                        const parent = this.currentParentCategory();
                        this.categoryParentId = parent ? (parent.parent_id || 0) : 0;
                    },
                    startCreatingCategory() {
                        this.creatingCategory = true;
                        this.creatingCategoryError = '';
                        this.newCategoryName = this.categorySearch.trim();
                        this.newCategoryParentId = this.categorySearch.trim() ? '' : (this.categoryParentId || '');
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
                            this.creatingCategory = false;
                            this.selectCategory(created.id);
                        }).catch(() => {
                            this.creatingCategoryError = 'Could not create category. Please try again.';
                        });
                    },
                }"
                x-on:keydown.escape.window="open = false"
                x-show="open"
                @add-category.window="add=true;open=true;bulkMode=false;categorySearch='';categoryParentId=0;creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;editing_category_id=0;loadSuggestions(event.detail.transaction_id);"
                @edit-category.window="add=false;open=true;bulkMode=false;categorySearch='';creatingCategory=false;transaction_id=event.detail.transaction_id;transaction_amount=event.detail.transaction_amount;transaction_name=event.detail.transaction_name;editing_category_id=event.detail.category_id;categoryParentId=(categoryLookup[event.detail.category_id]?.parent_id || 0);loadSuggestions(event.detail.transaction_id);"
                @bulk-add-category.window="add=true;open=true;bulkMode=true;categorySearch='';categoryParentId=0;creatingCategory=false;editing_category_id=0;suggestions=[];suggestionsLoading=false;"
            >
                <div class="fixed inset-0 bg-zinc-900/50" @click="open = false"></div>
                <div class="bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white p-4 rounded-xl w-full max-w-96 max-h-[85vh] z-10 flex flex-col overflow-hidden shadow-xl">
                    <div class="flex flex-col gap-4 min-h-0 flex-1" x-show="!creatingCategory">
                        <div class="flex items-center justify-between">
                            <span x-show="bulkMode">Assign Category</span>
                            <span x-show="!bulkMode && add">Add Category</span>
                            <span x-show="!bulkMode && !add">Edit Category</span>
                            <button
                                type="button"
                                x-show="!bulkMode && editing_category_id > 0"
                                @click="open = false; clearCategory(transaction_id)"
                                class="cursor-pointer text-xs text-red-500 hover:text-red-600"
                            >Clear category</button>
                        </div>
                        <div class="flex justify-between" x-show="!bulkMode">
                            <div><span x-html="transaction_name"></span> (#<span x-text="transaction_id"></span>)</div>
                            <span x-html="transaction_amount"></span>
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400" x-show="bulkMode">
                            <span x-text="selected_transactions.length"></span> transaction(s) selected
                        </div>

                        <div class="flex flex-col gap-1 shrink-0" x-show="!bulkMode && suggestionsLoading" x-cloak>
                            <div class="px-2 py-1.5 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400 text-sm flex items-center gap-2">
                                <flux:icon.loading class="size-4 shrink-0" />
                                Loading suggestions...
                            </div>
                        </div>

                        <div class="flex flex-col gap-1 shrink-0" x-show="!bulkMode && !suggestionsLoading && suggestions.length > 0">
                            <template x-for="suggestion in suggestions" :key="suggestion.id">
                                <button
                                    type="button"
                                    @click="open = false; applyCategory(transaction_id, suggestion.id)"
                                    class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                >
                                    <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${suggestion.color}`"></span>
                                    Suggested: <span x-text="suggestion.name"></span>
                                </button>
                            </template>
                        </div>

                        <x-input type="text" x-model="categorySearch" placeholder="Search categories..." class="w-full shrink-0"></x-input>

                        <button
                            type="button"
                            x-show="!categorySearch.trim() && categoryParentId !== 0"
                            @click="drillUp()"
                            class="cursor-pointer flex items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300 hover:text-zinc-900 dark:hover:text-white shrink-0"
                        >
                            <flux:icon.chevron-left class="size-4 shrink-0" />
                            <span x-text="currentParentCategory()?.name || 'All Categories'"></span>
                        </button>

                        <div class="overflow-y-auto flex flex-col gap-1 min-h-0 flex-1">
                            <template x-if="categorySearch.trim()">
                                <template x-for="cat in filteredCategories()" :key="cat.id">
                                    <button
                                        type="button"
                                        @click="selectCategory(cat.id)"
                                        class="cursor-pointer text-left px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                        :class="{ 'bg-zinc-100 dark:bg-white/10 ring-1 ring-inset ring-zinc-300 dark:ring-zinc-600': cat.id === editing_category_id }"
                                    >
                                        <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${cat.color}`"></span>
                                        <span x-text="cat.full_name" class="grow"></span>
                                        <flux:icon.check x-show="cat.id === editing_category_id" class="size-4 shrink-0" />
                                    </button>
                                </template>
                            </template>

                            <template x-if="!categorySearch.trim()">
                                <template x-for="cat in categoriesAtCurrentLevel()" :key="cat.id">
                                    <div class="flex items-center gap-1">
                                        <button
                                            type="button"
                                            x-show="categoryHasChildren(cat.id)"
                                            @click="drillInto(cat.id)"
                                            title="Browse subcategories"
                                            class="cursor-pointer shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-500 dark:text-zinc-400"
                                        >
                                            <flux:icon.chevron-right class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            @click="selectCategory(cat.id)"
                                            class="grow cursor-pointer text-left px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 flex items-center gap-2"
                                            :class="{ 'bg-zinc-100 dark:bg-white/10 ring-1 ring-inset ring-zinc-300 dark:ring-zinc-600': cat.id === editing_category_id }"
                                        >
                                            <span class="inline-block size-3 rounded-full shrink-0" :style="`background-color: ${cat.color}`"></span>
                                            <span x-text="cat.name" class="grow"></span>
                                            <flux:icon.check x-show="cat.id === editing_category_id" class="size-4 shrink-0" />
                                        </button>
                                        <button
                                            type="button"
                                            x-show="categoryHasChildren(cat.id)"
                                            @click="drillInto(cat.id)"
                                            title="Browse subcategories"
                                            class="cursor-pointer shrink-0 p-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-zinc-500 dark:text-zinc-400"
                                        >
                                            <flux:icon.chevron-right class="size-4" />
                                        </button>
                                    </div>
                                </template>
                            </template>

                            <div x-show="(categorySearch.trim() ? filteredCategories() : categoriesAtCurrentLevel()).length === 0" class="text-zinc-500 dark:text-zinc-400 text-sm px-2 py-1.5">No matching categories</div>
                        </div>

                        <button
                            type="button"
                            @click="startCreatingCategory()"
                            class="cursor-pointer text-left px-2 py-1.5 rounded-lg border border-dashed border-zinc-400 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-white/10 shrink-0"
                        >+ Create new category</button>

                        <flux:button variant="subtle" class="w-full shrink-0" @click="open = false">Cancel</flux:button>
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
