<?php

declare(strict_types=1);

use App\Actions\Reports\BuildIncomeExpenseTrendAction;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    #[Session]
    public string $granularity = 'monthly';

    public string $date_from = '';

    public string $date_to = '';

    public array $chart_periods = [];

    public array $chart_series = [];

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

    public function with(): array
    {
        $accounts = $this->trackedAccounts();

        $trend = BuildIncomeExpenseTrendAction::run(
            $accounts,
            Carbon::parse($this->date_from),
            Carbon::parse($this->date_to),
            $this->granularity,
        );

        $this->chart_periods = $trend['periods'];
        $this->chart_series = [
            ['label' => 'Income', 'color' => '#10b981', 'values' => $trend['income']],
            ['label' => 'Expense', 'color' => '#ef4444', 'values' => $trend['expense']],
        ];

        $incomeTotal = array_sum($trend['income']);
        $expenseTotal = array_sum($trend['expense']);

        return [
            'incomeTotal' => $incomeTotal,
            'expenseTotal' => $expenseTotal,
            'netTotal' => $incomeTotal - $expenseTotal,
            'hasData' => count(array_filter($trend['income'])) > 0 || count(array_filter($trend['expense'])) > 0,
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
    </div>

    <div wire:key="income-expense-trend-{{ $date_from }}-{{ $date_to }}-{{ $granularity }}">
        @if ($hasData)
            <x-period-chart type="bar" title="Income vs Expense" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-zinc-500 dark:text-zinc-400">
                Nothing to chart for the current date range.
            </div>
        @endif
    </div>

</x-page-wrapper>
