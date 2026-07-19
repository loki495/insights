<?php

declare(strict_types=1);

use App\Actions\Reports\BuildCategoryBreakdownTrendAction;
use App\Actions\Reports\BuildIncomeExpenseTrendAction;
use App\Models\Account;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    #[Session]
    public string $granularity = 'monthly';

    #[Session]
    public array $category_ids = [];

    public string $date_from = '';

    public string $date_to = '';

    public array $chart_periods = [];

    public array $chart_series = [];

    public string $chart_type = 'bar';

    public bool $chart_stacked = false;

    public function mount(): void
    {
        $this->date_from = (string) carbon()->startOfYear();
        $this->date_to = (string) carbon()->now();
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

    public function with(): array
    {
        $accounts = $this->trackedAccounts();
        $from = Carbon::parse($this->date_from);
        $to = Carbon::parse($this->date_to);

        // Always computed, regardless of the category filter below — the summary cards stay a
        // stable overall picture; only the trend chart's shape changes with category selection.
        $trend = BuildIncomeExpenseTrendAction::run($accounts, $from, $to, $this->granularity);
        $incomeTotal = array_sum($trend['income']);
        $expenseTotal = array_sum($trend['expense']);

        if (empty($this->category_ids)) {
            $this->chart_periods = $trend['periods'];
            $this->chart_series = [
                ['label' => 'Income', 'color' => '#10b981', 'values' => $trend['income']],
                ['label' => 'Expense', 'color' => '#ef4444', 'values' => $trend['expense']],
            ];
            $this->chart_type = 'bar';
            $this->chart_stacked = false;
            $hasData = count(array_filter($trend['income'])) > 0 || count(array_filter($trend['expense'])) > 0;
        } else {
            $breakdown = BuildCategoryBreakdownTrendAction::run($accounts, $from, $to, $this->granularity, $this->category_ids);
            $this->chart_periods = $breakdown['periods'];
            $this->chart_series = $breakdown['series'];
            $this->chart_type = 'area';
            $this->chart_stacked = true;
            $hasData = collect($breakdown['series'])->contains(fn ($series) => count(array_filter($series['values'])) > 0);
        }

        return [
            'incomeTotal' => $incomeTotal,
            'expenseTotal' => $expenseTotal,
            'netTotal' => $incomeTotal - $expenseTotal,
            'hasData' => $hasData,
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
            <x-input type="datetime-local" wire:model.live="date_from" class="w-full"></x-input>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">To</label>
            <x-input type="datetime-local" wire:model.live="date_to" class="w-full"></x-input>
        </div>
        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Granularity</label>
            <flux:select wire:model.live="granularity" class="w-full sm:w-40">
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

    <div wire:key="income-expense-trend-{{ $date_from }}-{{ $date_to }}-{{ $granularity }}-{{ implode(',', $category_ids) }}">
        @if ($hasData)
            <x-period-chart title="Income / Expense Trend" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-zinc-500 dark:text-zinc-400">
                Nothing to chart for the current date range.
            </div>
        @endif
    </div>

</x-page-wrapper>
