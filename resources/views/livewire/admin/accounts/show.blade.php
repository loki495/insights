<?php

declare(strict_types=1);

use App\Actions\PullLinkedAccountTransactionsAction;
use App\Models\Account;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public Account $account;

    #[Session]
    public string $search = '';

    public function mount(Account $account): void
    {
        //$account->transactions()->delete();
        $this->account = $account;
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

    public function pullData(): void
    {
        PullLinkedAccountTransactionsAction::run($this->account->linkedAccount);
    }

    public function with(): array
    {
        $transactions = $this->account->transactions()->with('originalCategory');

        if ($this->search) {
            $terms = $this->parseSearch($this->search);
            $transactions->where(function ($q) use ($terms) {
                $q->where(function ($q1) use ($terms) {
                    // Required terms
                    foreach ($terms['required'] as $term) {
                        $q1->where(function ($q2) use ($term) {
                            $q2->where('name', 'like', '%' . $term . '%')
                                ->orWhere('merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }

                    // Optional terms
                    foreach ($terms['optional'] as $term) {
                        $q1->orWhere(function ($q2) use ($term) {
                            $q2->where('name', 'like', '%' . $term . '%')
                                ->orWhere('merchant_name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'name', 'like', '%' . $term . '%')
                                ->orWhereRelation('originalCategory', 'description', 'like', '%' . $term . '%');
                        });
                    }
                });

                // Excluded terms
                foreach ($terms['excluded'] as $term) {
                    $q->where(function ($q1) use ($term) {
                        $q1->where('name', 'not like', '%' . $term . '%')
                            ->where(function ($q2) use ($term) {
                                $q2->where('merchant_name', 'not like', '%' . $term . '%')
                                    ->orWhereNull('merchant_name');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('name', 'like', '%' . $term . '%');
                            })
                            ->whereDoesntHave('originalCategory', function ($q2) use ($term) {
                                $q2->where('description', 'like', '%' . $term . '%');
                            });
                    });
                }

            });
        }

        return [
            'transactions' => $transactions->orderBy('created_at', 'desc')->paginate(10),
        ];
    }

}

?>
    <x-page-wrapper heading="Account Transactions" :subheading="$this->account->linkedAccount->provider_name . ' - ' . $this->account->name" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index', 'Accounts' => route('linked-accounts.accounts.index', $this->account->linkedAccount) ]">

        <x-slot name="actions">
            <x-button wire:click="pullData">Pull Data</x-button>
        </x-slot>

        <div class="flex gap-4 items-start w-full justify-between">
            <div class="flex gap-4 items-center w-full md:w-1/2 shrink">
                <label for="search">Search</label>
                <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full" clearable></x-input>
            </div>
            @if($transactions)
            {{ $transactions->links() }}
            @endif
        </div>

        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th class="text-center w-8">Date</x-table.th>
                    <x-table.th>Source</x-table.th>
                    <x-table.th>Amount</x-table.th>
                    <x-table.th>Running Balance</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
            @foreach($transactions ?? [] as $transaction)
                <x-table.tr class="hover:bg-zinc-100 dark:hover:bg-zinc-900/20 border-b border-zinc-300 dark:border-zinc-700 cursor-normal">
                    <x-table.td class="text-center">{{ \Carbon\Carbon::parse($transaction['created_at'])->format('m/d/Y') }}</x-table.td>
                    <x-table.td class="max-w-lg">
                        <div class="text-sm flex items-center gap-4">
                            <div>
                                <img title="{{ $transaction['originalCategory']['name'] }}" alt="{{ $transaction['originalCategory']['name'] }}" class="w-8 invert" src="{{ $transaction['originalCategory']['logo_url'] }}">
                            </div>
                            <div>
                                {{ $transaction['name'] }}
                                <br>
                                <small>{{ $transaction['originalCategory']['name'] }} - {{ $transaction['originalCategory']['details'] }}</small>
                                <small>({{ $transaction['payment_channel'] }}) </small>
                            </div>
                        </div>
                    </x-table.td>
                    <x-table.td class="text-right">{!! currency(-$transaction['amount'], $transaction['currency']) !!}</x-table.td>
                    <x-table.td class="text-right">{!! currency($transaction['running_balance'], $transaction['currency']) !!}</x-table.td>
                </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>

        @if($transactions)
        {{ $transactions->links() }}
        @endif

    </x-page-wrapper>
