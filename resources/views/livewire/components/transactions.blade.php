<?php

use App\Actions\BuildCategoryBreakdownForFilteredTransactionsAction;
use App\Actions\BuildTransactionsQueryAction;
use App\Actions\TransactionFilters;
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

    private function currentFilters(): TransactionFilters
    {
        return new TransactionFilters(
            account: $this->account,
            accountIds: $this->account_ids,
            categoryId: $this->category_id,
            originalCategoryId: $this->original_category_id,
            onlyUncategorized: (bool) $this->only_uncategorized,
            typeFilters: $this->type_filters,
            amountMin: $this->amount_min,
            amountMax: $this->amount_max,
            dateFrom: $this->date_from,
            dateTo: $this->date_to,
            search: $this->search,
        );
    }

    public function getTransactionsQuery(): Builder
    {
        return BuildTransactionsQueryAction::run(auth()->user(), $this->currentFilters());
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
        $this->category = Category::find($value);
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
        $breakdown = BuildCategoryBreakdownForFilteredTransactionsAction::run(
            $this->getTransactionsQuery(),
            $this->category_id,
        );

        $this->chart_ids = $breakdown['ids'];
        $this->chart_labels = $breakdown['labels'];
        $this->chart_values = $breakdown['values'];
        $this->chart_colors = $breakdown['colors'];
        $this->chart_tooltip_labels = $breakdown['tooltipLabels'];

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
