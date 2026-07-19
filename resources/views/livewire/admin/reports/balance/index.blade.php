<?php

declare(strict_types=1);

use App\Actions\Reports\BuildBalanceTrendAction;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    private const array LIABILITY_TYPES = ['credit', 'loan'];

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
            ->with('linked_account')
            ->get();
    }

    public function with(): array
    {
        $accounts = $this->trackedAccounts();

        $assetAccounts = $accounts->reject(fn (Account $account) => in_array($account->type, self::LIABILITY_TYPES, true))->values();
        $liabilityAccounts = $accounts->filter(fn (Account $account) => in_array($account->type, self::LIABILITY_TYPES, true))->values();

        $assetsTotal = (float) $assetAccounts->sum('current_balance');
        $liabilitiesTotal = (float) $liabilityAccounts->sum('current_balance');

        $trend = BuildBalanceTrendAction::run(
            $accounts,
            Carbon::parse($this->date_from),
            Carbon::parse($this->date_to),
            $this->granularity,
        );

        $this->chart_periods = $trend['periods'];
        $this->chart_series = [
            ['label' => 'Net Cash', 'color' => '#3b82f6', 'values' => $trend['net']],
        ];

        return [
            'assetAccounts' => $assetAccounts,
            'liabilityAccounts' => $liabilityAccounts,
            'assetsTotal' => $assetsTotal,
            'liabilitiesTotal' => $liabilitiesTotal,
            'netTotal' => $assetsTotal - $liabilitiesTotal,
        ];
    }
}

?>
<x-page-wrapper heading="Balance" subheading="Net cash across your tracked accounts" :breadcrumbs="['Reports' => null, 'Balance' => null]">

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Assets</div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{!! currency($assetsTotal, 'USD', true) !!}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Liabilities</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400">{!! currency($liabilitiesTotal, 'USD', true) !!}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-4 bg-white dark:bg-zinc-800">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">Net Cash</div>
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

    <div wire:key="balance-trend-{{ $date_from }}-{{ $date_to }}-{{ $granularity }}">
        @if ($assetAccounts->isNotEmpty() || $liabilityAccounts->isNotEmpty())
            <x-period-chart type="area" title="Net Cash" />
        @else
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-600 p-8 text-center text-zinc-500 dark:text-zinc-400">
                Nothing to chart for the current date range.
            </div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-2">
            <flux:heading size="md">Assets</flux:heading>
            @forelse ($assetAccounts as $account)
                <div wire:key="asset-{{ $account->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                    <span>{{ $account->display_name }}</span>
                    <span class="font-medium">{!! currency($account->current_balance, $account->currency, true) !!}</span>
                </div>
            @empty
                <div class="text-sm text-zinc-500 dark:text-zinc-400">No tracked asset accounts.</div>
            @endforelse
        </div>
        <div class="flex flex-col gap-2">
            <flux:heading size="md">Liabilities</flux:heading>
            @forelse ($liabilityAccounts as $account)
                <div wire:key="liability-{{ $account->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2">
                    <span>{{ $account->display_name }}</span>
                    <span class="font-medium">{!! currency($account->current_balance, $account->currency, true) !!}</span>
                </div>
            @empty
                <div class="text-sm text-zinc-500 dark:text-zinc-400">No tracked liability accounts.</div>
            @endforelse
        </div>
    </div>

</x-page-wrapper>
