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
        PullLinkedAccountTransactionsAction::run($this->account->linked_account);
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
    <x-page-wrapper heading="Account Transactions" :subheading="$this->account->linked_account->provider_name . ' - ' . $this->account->name" :breadcrumbs="['Linked Institutions' => 'linked-accounts.index', 'Accounts' => route('linked-accounts.accounts.index', $this->account->linked_account) ]">

        <x-slot name="actions">
            <x-button wire:click="pullData">Pull Data</x-button>
        </x-slot>

        <livewire:components.transactions :account="$account"></livewire:components.transactions>

    </x-page-wrapper>
