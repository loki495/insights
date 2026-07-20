<?php

declare(strict_types=1);

use App\Actions\Reports\BuildBalanceTrendAction;
use App\Livewire\Concerns\HasDisplayTimezoneDateRange;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new class extends Component
{
    use HasDisplayTimezoneDateRange;

    private const array LIABILITY_TYPES = ['credit', 'loan'];

    #[Session]
    public string $granularity = 'monthly';

    #[Session]
    public array $account_ids = [];

    public string $date_from = '';

    public string $date_to = '';

    public string $date_from_local = '';

    public string $date_to_local = '';

    public array $chart_periods = [];

    public array $chart_series = [];

    public string $chart_type = 'area';

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

    /**
     * All selectable options for the account filter dropdown — unaffected by the filter itself.
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts()
    {
        return $this->trackedAccounts()->sortBy(fn (Account $account): string => $account->linked_account->provider_name.' - '.$account->display_name)->values();
    }

    public function with(): array
    {
        $accounts = $this->trackedAccounts();

        if ($this->account_ids !== []) {
            // Intersect against the user's own tracked accounts rather than trusting the ids
            // directly — same IDOR-safety convention as the transaction list's account filter.
            $accounts = $accounts->whereIn('id', $this->account_ids)->values();
        }

        $assetAccounts = $accounts->reject(fn (Account $account): bool => in_array($account->type, self::LIABILITY_TYPES, true))->values();
        $liabilityAccounts = $accounts->filter(fn (Account $account): bool => in_array($account->type, self::LIABILITY_TYPES, true))->values();

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
        <div class="flex flex-col gap-1 w-full sm:w-auto" x-data="{ accountsOpen: false }">
            <label class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Account</label>
            <div class="relative w-full sm:w-64" @click.outside="accountsOpen = false">
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
                            {{ $selectedAccount ? $selectedAccount->linked_account->provider_name.' - '.$selectedAccount->display_name : '1 account selected' }}
                        @else
                            {{ count($account_ids) }} accounts selected
                        @endif
                    </span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-500" />
                </button>

                <div
                    id="balance-accounts-filter-dropdown"
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
                        label="{{ $account_option->linked_account->provider_name }} - {{ $account_option->display_name }}"
                    />
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div wire:key="balance-trend-{{ $date_from }}-{{ $date_to }}-{{ $granularity }}-{{ implode(',', $account_ids) }}">
        @if ($assetAccounts->isNotEmpty() || $liabilityAccounts->isNotEmpty())
            <x-period-chart title="Net Cash" />
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
