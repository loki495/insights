<?php

use App\Livewire\Concerns\HasCategoryAssignment;
use App\Livewire\Concerns\HasDisplayTimezoneDateRange;
use App\Livewire\Concerns\HasTypeAndTransferPairing;
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
    use HasCategoryAssignment;
    use HasDisplayTimezoneDateRange;
    use HasTypeAndTransferPairing;
    use WithPagination;

    #[Locked]
    public bool $allow_accounts;

    #[Locked]
    public bool $allow_running_balance;

    #[Session]
    public $only_uncategorized = false;

    #[Session]
    public array $account_ids = [];

    #[Session]
    public array $type_filters = [];

    #[Session]
    public string $amount_min = '';

    #[Session]
    public string $amount_max = '';

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

    public string $date_from_local = '';

    public string $date_to_local = '';

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

        $range = $this->defaultYearToDateRange();
        $this->date_from = $range['from'];
        $this->date_to = $range['to'];
        $this->date_from_local = $range['from_local'];
        $this->date_to_local = $range['to_local'];

        $this->updateChartData();
    }

    public function updatedDateFromLocal(string $value): void
    {
        $this->date_from = $this->fromDisplayTimezone($value);
    }

    public function updatedDateToLocal(string $value): void
    {
        $this->date_to = $this->fromDisplayTimezone($value);
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

        // Aggregate views (no specific account picked) only pull from tracked accounts —
        // reference/excluded accounts stay out of cross-account reports by design. Viewing a
        // single account directly (below) is unaffected.
        $ownedAccountIds = auth()->user()->accounts()->tracked()->pluck('accounts.id');

        if ($this->account?->id) {
            $this->authorize('view', $this->account);
            $query->where('account_id', $this->account->id);
        } elseif ($this->account_ids !== []) {
            // Only show transactions from selected accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds->intersect($this->account_ids)->values());
        } else {
            // If no account is selected, only show transactions from accounts the user owns
            $query->whereIn('account_id', $ownedAccountIds);
        }

        $query
            ->when($this->original_category_id ?? false, fn ($query) => $query->where('original_category_id', $this->original_category_id))
            ->when($this->category_id ?? false, function ($query) {
                $category = Category::find($this->category_id);
                $category_id = $category->id;
                $descendants = $category->descendants;

                return $query->whereHas('categories', function ($query) use ($category_id, $descendants): void {
                    $query
                        ->where('categories.id', $category_id)
                        ->orWhereIn('categories.id', $descendants);
                });
            })
            ->when($this->only_uncategorized ?? false, fn ($query) => $query->doesntHave('categories'))
            ->when($this->type_filters !== [], fn ($query) => $query->whereIn('type', $this->type_filters))
            // Filtered on the transaction's magnitude, not its signed amount — a user thinking
            // "between $50 and $200" doesn't want to also have to know/guess the sign, and the
            // Type filter above already covers direction (income/expense/transfer/adjustment).
            // The CAST is load-bearing: SQLite/PDO binds `?` with TEXT affinity, and comparing
            // that against a function-call expression like ABS(amount) (which carries no column
            // affinity of its own) makes SQLite fall back to a lexicographic string comparison —
            // "1000" < "500" alphabetically — silently corrupting the filter for any value with a
            // different digit count. Forcing both sides numeric avoids that.
            ->when($this->amount_min !== '', fn ($query) => $query->whereRaw('ABS(amount) >= CAST(? AS REAL)', [(float) $this->amount_min]))
            ->when($this->amount_max !== '', fn ($query) => $query->whereRaw('ABS(amount) <= CAST(? AS REAL)', [(float) $this->amount_max]))
            ->with('categories')
            ->with('originalCategory')
            ->whereBetween('transactions.created_at', [$this->date_from, $this->date_to]);

        if ($this->search !== '' && $this->search !== '0') {
            $terms = $this->parseSearch($this->search);

            // Dynamically build the relevance selectRaw
            $bindings = [];
            $scoreParts = [];

            foreach ($terms['optional'] as $term) {
                foreach (['transactions.name', 'transactions.merchant_name', 'original_categories.name', 'original_categories.pf_detailed'] as $field) {
                    $scoreParts[] = "CASE WHEN LOWER($field) LIKE ? THEN 1 ELSE 0 END";
                    $bindings[] = '%'.strtolower((string) $term).'%';
                }
            }

            if ($scoreParts !== []) {
                $scoreExpr = implode(' + ', $scoreParts);
                $query
                    ->leftJoin('original_categories', 'transactions.original_category_id', '=', 'original_categories.id')
                    ->selectRaw("transactions.*, ($scoreExpr) as relevance", $bindings);
            } else {
                $query
                    ->selectRaw('transactions.*, 0 as relevance');
            }

            $query->where(function ($q) use ($terms): void {
                $q->where(function ($q1) use ($terms): void {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term): void {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term): void {
                            $q2->where('transactions.name', 'like', '%'.$term.'%')
                                ->orWhere('transactions.merchant_name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%'.$term.'%')
                                ->orWhereRelation('originalCategory', 'pf_detailed', 'like', '%'.$term.'%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term): void {
                        $q1->where('transactions.name', 'not like', '%'.$term.'%')
                            ->where(function ($q2) use ($term): void {
                                $q2->where('transactions.merchant_name', 'not like', '%'.$term.'%')
                                    ->orWhereNull('transactions.merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term): void {
                                $q2->where('name', 'like', '%'.$term.'%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term): void {
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

    public function updating(): void
    {
        $this->resetPage();
    }

    /**
     * The chart aggregates the full (unpaginated) date-range query, so it
     * doesn't need recomputing on a page-only navigation (nextPage/
     * previousPage/gotoPage bypass this hook entirely, since they set
     * $this->paginators directly rather than syncing a client property).
     */
    public function updated($name): void
    {
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

    public function updatedCategoryId($value = null): void
    {
        $this->original_category = OriginalCategory::find($value);
        $this->dispatch('categoryIdChanged', categoryId: $value);
    }

    #[Computed]
    public function accounts()
    {
        // Scoped to the authenticated user's own tracked accounts — reference/excluded accounts
        // shouldn't appear as a filterable option in aggregate views.
        $accounts = auth()->user()->accounts()->tracked()->with('linked_account')->get()->sortBy(fn ($account): string => $account->linked_account->provider_name.' - '.$account->display_name);

        return $accounts;
    }

    public function updateChartData(): void
    {
        $query = $this->getTransactionsQuery();

        // Deliberately not scoped to reportable() — the transaction list/chart here (account view,
        // transaction search) shows everything matching the current filters, transfers included;
        // that's what categories are for. Excluding transfers from aggregate income/expense totals
        // is the dedicated Reports pages' job (see BuildIncomeExpenseTrendAction), not this one.
        $transactions = $query
            ->clone()
            ->with(['categories' => function ($q): void {
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

                if ($current_filtered_id === 0) {
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
                        $target = $filter_index !== false && $filter_index > 0 ? $all_categories->get($path[$filter_index - 1]) : $category;
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
        $this->chart_values = $chart_data->pluck('total')->map(fn ($v): float => round(abs($v), 2))->toArray();
        $this->chart_colors = $chart_data->pluck('color')->toArray();

        $abs_total = $total_sum;
        $this->chart_tooltip_labels = $chart_data->map(function (array $item) use ($abs_total): string {
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
    public function handleChartClick($categoryId): void
    {
        if ($categoryId == 0) {
            return;
        } // Uncategorized or invalid

        $this->category_id = (int) $categoryId;
        $this->category = Category::find($this->category_id);
        $this->chartNeedsRefresh = true;
        $this->resetPage();
    }

    public function goBack(): void
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

    public function deleteTransaction($transaction_id): void
    {
        $transaction = Transaction::findOrFail($transaction_id);
        $this->authorize('delete', $transaction);
        $transaction->categories()->detach();
        $transaction->delete();
        $this->chartNeedsRefresh = true;
    }

    public function bulkDeleteTransactions(array $transaction_ids): void
    {
        // Only manually-added transactions can be deleted (matches the
        // single-delete action); Plaid-synced ones are silently skipped.
        $transactions = Transaction::whereIn('id', $transaction_ids)
            ->get()
            ->filter(fn (Transaction $transaction) => $transaction->original['manual'] ?? false);

        foreach ($transactions as $transaction) {
            $this->authorize('delete', $transaction);
            $transaction->categories()->detach();
            $transaction->delete();
        }

        $this->chartNeedsRefresh = true;
    }
}
?>
    <div
        x-data="{
            optimisticCategories: {},
            optimisticTypes: {},
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
                $wire.bulkAssignCategory(categoryId, this.selected_transactions).then(() => {
                    this.selected_transactions = [];
                    this.selectMode = false;
                });
            },
            bulkAssignType(type) {
                $wire.bulkAssignType(type, this.selected_transactions).then(() => {
                    this.selected_transactions = [];
                    this.selectMode = false;
                });
            },
            bulkDeleteTransactions() {
                $wire.bulkDeleteTransactions(this.selected_transactions).then(() => {
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

            {{--
                Alpine-controlled, not a native <details> — its `open` attribute lives only in the
                client DOM, so Livewire's morph on every re-render (pagination, search, filter
                changes) would reset it to closed. Alpine's x-data state survives those morphs.

                Always rendered (like Filters/Details below), even with nothing to chart yet, so an
                empty result for the current filters reads as empty rather than missing.
            --}}
            <div class="w-full rounded-xl bg-zinc-100 dark:bg-white/10" x-data="{ chartOpen: false }">
                <button type="button" @click="chartOpen = !chartOpen" class="cursor-pointer select-none p-2 font-medium w-full text-left flex items-center gap-1">
                    <span class="transition-transform" :class="{ 'rotate-90': chartOpen }">
                        <flux:icon.chevron-right class="size-3!" />
                    </span>
                    Chart
                </button>
                <div x-show="chartOpen" x-cloak class="p-2 pt-0">
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
                    @else
                    <div class="text-sm text-zinc-500 dark:text-zinc-400 py-2">Nothing to chart for the current filters.</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-4 lg:flex-row lg:gap-8 w-full items-start justify-between">
            @include('livewire.components.partials.transaction-filters')

            <div class="w-full lg:hidden shrink-0 rounded-xl bg-zinc-100 dark:bg-white/10" x-data="{ detailsOpen: false }">
                <button type="button" @click="detailsOpen = !detailsOpen" class="cursor-pointer select-none p-2 font-medium w-full text-left flex items-center gap-1">
                    <span class="transition-transform" :class="{ 'rotate-90': detailsOpen }">
                        <flux:icon.chevron-right class="size-3!" />
                    </span>
                    Details
                </button>
                <div x-show="detailsOpen" x-cloak class="p-2 pt-0">
                    @include('livewire.components.partials.transaction-summary-details')
                </div>
            </div>

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
                <div class="relative" x-data="{ assignTypeOpen: false }" @click.outside="assignTypeOpen = false">
                    <flux:button size="sm" variant="primary" class="cursor-pointer w-full" @click="assignTypeOpen = !assignTypeOpen">Assign Type</flux:button>
                    <div
                        x-show="assignTypeOpen"
                        x-cloak
                        class="absolute z-20 mt-1 min-w-full rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 shadow-lg p-1 flex flex-col gap-1"
                    >
                        <button type="button" class="cursor-pointer text-left text-sm px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-nowrap" @click="assignTypeOpen = false; bulkAssignType('income')">Income</button>
                        <button type="button" class="cursor-pointer text-left text-sm px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-nowrap" @click="assignTypeOpen = false; bulkAssignType('expense')">Expense</button>
                        <button type="button" class="cursor-pointer text-left text-sm px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-nowrap" @click="assignTypeOpen = false; bulkAssignType('transfer')">Transfer</button>
                        <button type="button" class="cursor-pointer text-left text-sm px-2 py-1.5 rounded-lg hover:bg-zinc-100 dark:hover:bg-white/10 text-nowrap" @click="assignTypeOpen = false; bulkAssignType('adjustment')">Adjustment</button>
                    </div>
                </div>
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
            loading-target="search,only_uncategorized,original_category_id,category_id,date_from_local,date_to_local,account_ids,amount_min,amount_max,page,nextPage,previousPage,gotoPage"
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

        @include('livewire.components.partials.transaction-category-picker-modal')

        @include('livewire.components.partials.transaction-type-editor-modal')
    </div>
