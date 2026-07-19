<?php

declare(strict_types=1);

use App\Actions\Reports\BuildCategoryBreakdownTrendAction;
use App\Actions\Reports\BuildIncomeExpenseTrendAction;
use App\Actions\Reports\Concerns\FiltersBySearchAndAmount;
use App\Livewire\Concerns\HasDisplayTimezoneDateRange;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use FiltersBySearchAndAmount;
    use HasDisplayTimezoneDateRange;
    use WithPagination;

    #[Session]
    public string $granularity = 'monthly';

    #[Session]
    public array $category_ids = [];

    #[Session]
    public string $search = '';

    #[Session]
    public string $amount_min = '';

    #[Session]
    public string $amount_max = '';

    public string $date_from = '';

    public string $date_to = '';

    public string $date_from_local = '';

    public string $date_to_local = '';

    public array $chart_periods = [];

    public array $chart_series = [];

    public string $chart_type = 'bar';

    public bool $chart_stacked = false;

    public function mount(): void
    {
        $range = $this->defaultYearToDateRange();
        $this->date_from = $range['from'];
        $this->date_to = $range['to'];
        $this->date_from_local = $range['from_local'];
        $this->date_to_local = $range['to_local'];
    }

    public function updatedDateFromLocal(string $value): void
    {
        $this->date_from = $this->fromDisplayTimezone($value);
    }

    public function updatedDateToLocal(string $value): void
    {
        $this->date_to = $this->fromDisplayTimezone($value);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    /**
     * @return Collection<int, Account>
     */
    private function trackedAccounts()
    {
        return auth()->user()->accounts()
            ->tracked()
            ->whereHas('linked_account', fn ($query) => $query->whereNull('closed_at'))
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::all()->sortBy('fullName')->values();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function transactionsQuery(Collection $accounts, Carbon $from, Carbon $to)
    {
        $query = Transaction::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->reportable()
            ->whereBetween('created_at', [$from, $to])
            ->with(['account.linked_account', 'categories']);

        if ($this->category_ids !== []) {
            $matchingIds = Category::whereIn('id', $this->category_ids)->get()
                ->flatMap(fn (Category $category) => $category->descendants)
                ->unique()
                ->values();

            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $matchingIds));
        }

        self::applySearchAndAmountFilters($query, $this->search, $this->amount_min, $this->amount_max);

        return $query;
    }

    public function with(): array
    {
        $accounts = $this->trackedAccounts();
        $from = Carbon::parse($this->date_from);
        $to = Carbon::parse($this->date_to);

        // Narrowed to the selected categories/search/amount range (if any) — the summary cards
        // reflect exactly what the chart and list below are showing, not the account-wide picture.
        $trend = BuildIncomeExpenseTrendAction::run($accounts, $from, $to, $this->granularity, $this->category_ids, $this->search, $this->amount_min, $this->amount_max);
        $incomeTotal = array_sum($trend['income']);
        $expenseTotal = array_sum($trend['expense']);

        if ($this->category_ids === []) {
            $this->chart_periods = $trend['periods'];
            $this->chart_series = [
                ['label' => 'Income', 'color' => '#10b981', 'values' => $trend['income']],
                ['label' => 'Expense', 'color' => '#ef4444', 'values' => $trend['expense']],
            ];
            $this->chart_type = 'bar';
            $this->chart_stacked = false;
            $hasData = count(array_filter($trend['income'])) > 0 || count(array_filter($trend['expense'])) > 0;
        } else {
            $breakdown = BuildCategoryBreakdownTrendAction::run($accounts, $from, $to, $this->granularity, $this->category_ids, $this->search, $this->amount_min, $this->amount_max);
            $this->chart_periods = $breakdown['periods'];
            $this->chart_series = $breakdown['series'];
            $this->chart_type = 'area';
            $this->chart_stacked = true;
            $hasData = collect($breakdown['series'])->contains(fn ($series): bool => count(array_filter($series['values'])) > 0);
        }

        $transactionsList = $this->transactionsQuery($accounts, $from, $to)
            ->orderByDesc('created_at')
            ->paginate(25);

        return [
            'incomeTotal' => $incomeTotal,
            'expenseTotal' => $expenseTotal,
            'netTotal' => $incomeTotal - $expenseTotal,
            'hasData' => $hasData,
            'transactionsList' => $transactionsList,
        ];
    }
}

?>
<x-page-wrapper heading="Income / Expense" subheading="How much came in vs. went out, over time" :breadcrumbs="['Reports' => null, 'Income / Expense' => null]">

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Income</div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{!! currency($incomeTotal, 'USD', true) !!}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Expense</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400">{!! currency($expenseTotal * -1, 'USD', true) !!}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Net</div>
            <div class="text-2xl font-bold">{!! currency($netTotal, 'USD', true) !!}</div>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-end">
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">From</label>
            <x-input type="datetime-local" wire:model.live="date_from_local" class="w-full"></x-input>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">To</label>
            <x-input type="datetime-local" wire:model.live="date_to_local" class="w-full"></x-input>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Granularity</label>
            <flux:select wire:model.live="granularity" class="w-full sm:w-40">
                <flux:select.option value="daily">Daily</flux:select.option>
                <flux:select.option value="monthly">Monthly</flux:select.option>
                <flux:select.option value="quarterly">Quarterly</flux:select.option>
                <flux:select.option value="yearly">Yearly</flux:select.option>
            </flux:select>
        </div>
        <div class="flex flex-col gap-1 w-full sm:w-64" x-data="{ categoriesOpen: false }">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Categories</label>
            <div class="relative w-full" @click.outside="categoriesOpen = false">
                <button
                    type="button"
                    @click="categoriesOpen = !categoriesOpen"
                    class="cursor-pointer flex items-center justify-between w-full px-3 py-2 rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm text-left"
                >
                    <span>
                        @if(empty($category_ids))
                            -- Income vs Expense --
                        @elseif(count($category_ids) === 1)
                            @php $selectedCategory = $this->categories->firstWhere('id', $category_ids[0]); @endphp
                            {{ $selectedCategory?->fullName ?? '1 category selected' }}
                        @else
                            {{ count($category_ids) }} categories selected
                        @endif
                    </span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-500" />
                </button>

                <div
                    x-show="categoriesOpen"
                    x-cloak
                    class="absolute z-20 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-800 shadow-lg p-2 flex flex-col gap-1"
                >
                    <button
                        type="button"
                        wire:click="$set('category_ids', [])"
                        class="cursor-pointer text-left px-2 py-1.5 rounded-lg text-sm text-blue-600 dark:text-blue-400 hover:bg-zinc-100 dark:hover:bg-white/10"
                    >Clear (Income vs Expense)</button>

                    @foreach($this->categories as $category_option)
                    <flux:checkbox
                        wire:model.live="category_ids"
                        value="{{ $category_option->id }}"
                        label="{{ $category_option->fullName }}"
                    />
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-end">
        <div class="flex flex-col gap-1 w-full sm:w-64">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Search</label>
            <x-input type="text" wire:model.live.debounce="search" placeholder="Name or merchant" class="w-full" clearable></x-input>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Amount</label>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <x-input type="number" step="0.01" min="0" wire:model.live.debounce="amount_min" placeholder="Min" class="w-full sm:w-32"></x-input>
                <span class="text-zinc-500 dark:text-zinc-400">–</span>
                <x-input type="number" step="0.01" min="0" wire:model.live.debounce="amount_max" placeholder="Max" class="w-full sm:w-32"></x-input>
            </div>
        </div>
    </div>

    <div wire:key="income-expense-trend-{{ $date_from }}-{{ $date_to }}-{{ $granularity }}-{{ implode(',', $category_ids) }}-{{ $search }}-{{ $amount_min }}-{{ $amount_max }}">
        @if ($hasData)
            <x-period-chart title="Income / Expense Trend" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-zinc-500 dark:text-zinc-400">
                Nothing to chart for the current date range.
            </div>
        @endif
    </div>

    <div class="flex flex-col gap-2">
        <flux:heading size="md">Transactions</flux:heading>

        <div class="hidden sm:block overflow-x-auto rounded-xl bg-zinc-100 dark:bg-white/10 p-4">
            <x-table class="min-w-full">
                <x-slot name="head">
                    <x-table.tr>
                        <x-table.th>Date</x-table.th>
                        <x-table.th>Account</x-table.th>
                        <x-table.th>Description</x-table.th>
                        <x-table.th>Category</x-table.th>
                        <x-table.th>Type</x-table.th>
                        <x-table.th class="text-right">Amount</x-table.th>
                    </x-table.tr>
                </x-slot>
                <x-slot name="body">
                    @forelse ($transactionsList as $transaction)
                        @php
                            $typeStyles = [
                                'income' => ['label' => 'Income', 'class' => 'bg-emerald-600'],
                                'expense' => ['label' => 'Expense', 'class' => 'bg-zinc-500'],
                                'transfer' => ['label' => 'Transfer', 'class' => 'bg-blue-600'],
                                'adjustment' => ['label' => 'Adjustment', 'class' => 'bg-amber-600'],
                            ];
                            $typeStyle = $typeStyles[$transaction->type ?? null] ?? null;
                        @endphp
                        <x-table.tr wire:key="income-expense-list-{{ $transaction->id }}">
                            <x-table.td class="text-nowrap">{{ $transaction->created_at->format('M j, Y') }}</x-table.td>
                            <x-table.td class="text-nowrap">{{ $transaction->account?->display_name ?? '—' }}</x-table.td>
                            <x-table.td>
                                <div>{{ $transaction->name }}</div>
                                @if($transaction->merchant_name && $transaction->merchant_name !== $transaction->name)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $transaction->merchant_name }}</div>
                                @endif
                            </x-table.td>
                            <x-table.td>
                                <div class="flex gap-1 flex-wrap">
                                    @foreach($transaction->categories as $category)
                                        <span
                                            class="text-xs px-1.5 py-0.5 rounded-md text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)]"
                                            style="background-color: {{ $category->color ?: '#3b82f6' }}"
                                        >{{ $category->name }}</span>
                                    @endforeach
                                </div>
                            </x-table.td>
                            <x-table.td>
                                @if($typeStyle)
                                    <span class="text-xs px-1.5 py-0.5 rounded-md text-nowrap text-white [text-shadow:0_1px_2px_rgb(0_0_0_/_70%)] {{ $typeStyle['class'] }}">{{ $typeStyle['label'] }}</span>
                                @endif
                            </x-table.td>
                            <x-table.td class="text-right text-nowrap">{!! currency($transaction->amount, $transaction->currency, true) !!}</x-table.td>
                        </x-table.tr>
                    @empty
                        <x-table.tr>
                            <x-table.td colspan="6" class="text-center py-4 text-zinc-500 dark:text-zinc-400">No transactions found.</x-table.td>
                        </x-table.tr>
                    @endforelse
                </x-slot>
            </x-table>
        </div>

        <div class="flex flex-col gap-2 sm:hidden">
            @forelse ($transactionsList as $transaction)
                <div wire:key="income-expense-card-{{ $transaction->id }}" class="rounded-xl bg-zinc-100 dark:bg-white/10 p-3 flex flex-col gap-1">
                    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ $transaction->created_at->format('M j, Y') }}</span>
                        <span>{{ $transaction->account?->display_name ?? '—' }}</span>
                    </div>
                    <div class="font-medium">{{ $transaction->name }}</div>
                    <div class="flex items-center justify-between">
                        <span class="font-semibold">{!! currency($transaction->amount, $transaction->currency, true) !!}</span>
                    </div>
                </div>
            @empty
                <div class="text-center py-4 text-zinc-500 dark:text-zinc-400">No transactions found.</div>
            @endforelse
        </div>

        <div class="w-full">
            {{ $transactionsList->links() }}
        </div>
    </div>

</x-page-wrapper>
