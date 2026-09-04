<?php

declare(strict_types=1);

use App\Actions\BuildCategoryBreakdownForFilteredTransactionsAction;
use App\Actions\Reports\BuildBalanceTrendAction;
use App\Models\Account;
use App\Models\Transaction;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Account types treated as liabilities (subtracted from Net) — same convention as the
     * dedicated Balance report.
     */
    private const array LIABILITY_TYPES = ['credit', 'loan'];

    public array $chart_periods = [];

    public array $chart_series = [];

    public string $chart_type = 'area';

    public bool $chart_stacked = false;

    public array $chart_ids = [];

    public array $chart_labels = [];

    public array $chart_values = [];

    public array $chart_colors = [];

    public array $chart_tooltip_labels = [];

    public function with(): array
    {
        $linkedAccounts = auth()->user()->linked_accounts()->with('accounts')->get();

        // The snapshot widgets (trend/spending/recent activity) only fold in accounts the user
        // wants counted in aggregates — same scope the Reports pages use. The account-card grid
        // below is unaffected and still shows every linked account, tracked or not.
        $trackedAccounts = auth()->user()->accounts()
            ->tracked()
            ->whereHas('linked_account', fn ($query) => $query->whereNull('closed_at'))
            ->get();
        $accountIds = $trackedAccounts->pluck('id');

        $nowLocal = now(config('app.display_timezone'));
        $trendFrom = $nowLocal->copy()->subDays(89)->startOfDay()->setTimezone(config('app.timezone'));
        $trendTo = $nowLocal->copy()->setTimezone(config('app.timezone'));

        $trend = BuildBalanceTrendAction::run($trackedAccounts, $trendFrom, $trendTo, 'daily');
        $this->chart_periods = $trend['periods'];
        $this->chart_series = [
            ['label' => 'Net Cash', 'color' => '#3b82f6', 'values' => $trend['net']],
        ];

        $assetsTotal = (float) $trackedAccounts->reject(fn (Account $account): bool => in_array($account->type, self::LIABILITY_TYPES, true))->sum('current_balance');
        $liabilitiesTotal = (float) $trackedAccounts->filter(fn (Account $account): bool => in_array($account->type, self::LIABILITY_TYPES, true))->sum('current_balance');

        $monthStart = $nowLocal->copy()->startOfMonth()->setTimezone(config('app.timezone'));

        $spendingQuery = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->reportable()
            ->where('amount', '<', 0)
            ->whereBetween('created_at', [$monthStart, $trendTo]);

        $breakdown = BuildCategoryBreakdownForFilteredTransactionsAction::run(auth()->user(), $spendingQuery, null);
        $this->chart_ids = $breakdown['ids'];
        $this->chart_labels = $breakdown['labels'];
        $this->chart_values = $breakdown['values'];
        $this->chart_colors = $breakdown['colors'];
        $this->chart_tooltip_labels = $breakdown['tooltipLabels'];

        $recentTransactions = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->with(['account.linked_account', 'categories'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return [
            'linkedAccounts' => $linkedAccounts,
            'netTotal' => $assetsTotal - $liabilitiesTotal,
            'hasTrackedAccounts' => $trackedAccounts->isNotEmpty(),
            'recentTransactions' => $recentTransactions,
        ];
    }
};

?>

<x-page-wrapper heading="Dashboard" subheading="Account Summaries">
    <div class="flex h-full w-full flex-1 flex-col gap-8 rounded-xl">
        @if($linkedAccounts->isNotEmpty())
            <div class="flex flex-col gap-2">
                <div class="flex items-baseline justify-between">
                    <flux:heading size="lg" weight="semibold">Net Cash</flux:heading>
                    <div class="text-xl font-bold">{!! currency($netTotal, 'USD', true) !!}</div>
                </div>
                @if($hasTrackedAccounts)
                    <div wire:key="dashboard-net-cash-trend">
                        <x-period-chart title="Net Cash Trend" />
                    </div>
                @else
                    <div class="rounded-xl border-2 border-dashed border-neutral-200 p-8 text-center text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                        No tracked accounts yet — link or track an account to see a net cash trend here.
                    </div>
                @endif
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-2">
                    <flux:heading size="lg" weight="semibold">Spending This Month</flux:heading>
                    @if(count($chart_labels) > 0)
                        <div wire:key="dashboard-spending-snapshot">
                            <x-chart title="Spending This Month" />
                        </div>
                    @else
                        <div class="flex h-64 items-center justify-center rounded-xl border-2 border-dashed border-neutral-200 p-8 text-center text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
                            No expenses this month yet — anything under Recent
                            Transactions is from an earlier month, or is income,
                            a transfer, or an adjustment.
                        </div>
                    @endif
                </div>

                <div class="flex min-w-0 flex-col gap-2">
                    <flux:heading size="lg" weight="semibold">Recent Transactions</flux:heading>
                    <div class="flex h-64 flex-col gap-1 overflow-y-auto rounded-xl border border-neutral-200 bg-white p-3 dark:border-neutral-700 dark:bg-neutral-800">
                        @forelse($recentTransactions as $transaction)
                            <a
                                href="{{ route('linked-accounts.accounts.show', [$transaction->account->linked_account, $transaction->account]) }}"
                                wire:navigate
                                wire:key="recent-transaction-{{ $transaction->id }}"
                                class="-mx-1 flex items-center justify-between gap-3 rounded-lg px-1 py-2 hover:bg-neutral-50 dark:hover:bg-neutral-700/50"
                            >
                                <div class="flex min-w-0 flex-col">
                                    <span class="truncate font-medium">{{ $transaction->merchant_name ?: $transaction->name }}</span>
                                    <span class="truncate text-xs text-neutral-500 dark:text-neutral-400">
                                        {{ $transaction->account->linked_account->provider_name }} &middot; {{ $transaction->account->display_name }} &middot; {{ $transaction->created_at->format('M j') }}
                                        @if($transaction->categories->isNotEmpty())
                                            &middot; {{ $transaction->categories->first()->fullName }}
                                        @endif
                                    </span>
                                </div>
                                <div class="shrink-0 font-semibold">{!! currency($transaction->amount, $transaction->currency) !!}</div>
                            </a>
                        @empty
                            <div class="flex flex-1 items-center justify-center text-sm text-neutral-500 dark:text-neutral-400">
                                No transactions yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        @foreach ($linkedAccounts as $linkedAccount)
            <div wire:key="linked-account-{{ $linkedAccount->id }}" class="flex flex-col gap-4">
                <flux:heading size="lg" weight="semibold">{{ $linkedAccount->provider_name }}</flux:heading>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($linkedAccount->accounts as $account)
                        <a
                            href="{{ route('linked-accounts.accounts.show', [$linkedAccount, $account]) }}"
                            wire:navigate
                            wire:key="account-{{ $account->id }}"
                            class="group relative flex flex-col justify-between overflow-hidden rounded-xl border border-neutral-200 bg-white p-6 transition-all hover:border-neutral-300 hover:shadow-sm dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-neutral-600"
                        >
                            <div class="flex flex-col gap-1">
                                <flux:text size="sm" class="text-neutral-500 dark:text-neutral-400">
                                    {{ $account->official_name ?: $account->name }}
                                </flux:text>
                                <flux:heading size="md" weight="bold" class="truncate">
                                    {{ $account->name }}
                                </flux:heading>
                            </div>

                            <div class="mt-4 flex items-baseline justify-between">
                                <div class="text-2xl font-bold tracking-tight">
                                    {!! currency($account->current_balance, $account->currency) !!}
                                </div>
                                @if($account->available_balance && $account->available_balance != $account->current_balance)
                                    <flux:text size="xs" class="text-neutral-400 dark:text-neutral-500">
                                        Avail: {!! currency($account->available_balance, $account->currency, true) !!}
                                    </flux:text>
                                @endif
                            </div>

                            <div class="mt-4 flex items-center justify-between border-t border-neutral-100 pt-4 dark:border-neutral-700/50">
                                <flux:badge size="sm" variant="subtle">
                                    {{ $account->subtype ?: $account->type }}
                                </flux:badge>
                                <flux:icon name="chevron-right" size="sm" class="text-neutral-300 transition-transform group-hover:translate-x-1 dark:text-neutral-600" />
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if($linkedAccounts->isEmpty())
            <div class="flex flex-1 flex-col items-center justify-center rounded-xl border-2 border-dashed border-neutral-200 p-12 text-center dark:border-neutral-700">
                <flux:heading size="lg" class="mb-2">No accounts linked yet</flux:heading>
                <flux:text class="mb-6">Link your first bank account to start tracking your finances.</flux:text>
                <flux:button href="{{ route('linked-accounts.index') }}" wire:navigate variant="primary">
                    Go to Linked Accounts
                </flux:button>
            </div>
        @endif
    </div>
</x-page-wrapper>
