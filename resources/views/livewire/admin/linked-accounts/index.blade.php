<?php

use App\Models\LinkedAccount;
use App\Services\Plaid\PlaidService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public array $linkedAccounts;

    public string $environment = '';

    private ?PlaidService $plaid_instance = null;

    // Tracks which LinkedAccount (if any) started the current Plaid Link flow, so
    // exchangePublicToken() knows whether to update it in place ("Update Access Token") or create
    // a new one ("Link Institution"). Locked so a tampered client payload can't redirect a
    // legitimately-completed Link flow's token onto an arbitrary other LinkedAccount.
    #[Locked]
    public ?int $updating_linked_account_id = null;

    protected $listeners = [
        'exchangePublicToken' => '$refresh',
    ];

    public function mount(): void
    {
        $this->authorize('viewAny', LinkedAccount::class);
        $this->updateLinkedAccount();
    }

    #[Computed]
    private function plaid(): PlaidService
    {

        if (! $this->plaid_instance instanceof PlaidService) {
            $this->plaid_instance = plaid();
        }

        return $this->plaid_instance;
    }

    public function linkAccount(?LinkedAccount $linkedAccount = null): void
    {
        if ($linkedAccount && $linkedAccount->id) {
            $this->authorize('update', $linkedAccount);
        }

        $this->updating_linked_account_id = $linkedAccount?->id ?: null;

        $data = [
            'client_name' => 'Insights',
            'products' => ['transactions'],
            'required_if_supported_products' => ['auth'],
            'country_codes' => ['US'],
            'language' => 'en',
            'user' => [
                'client_user_id' => (string) auth()->user()->id,
                // 'phone_number' => '415-555-0012',
            ],
        ];

        if ($linkedAccount && $linkedAccount->id > 0) {
            $data['access_token'] = $linkedAccount->access_token;
        }

        $response = $this->plaid->getLinkToken(data: $data);

        $link_token = $response['link_token'];

        $this->dispatch('triggerPlaid', link_token: $link_token);
    }

    #[On('exchangePublicToken')]
    public function exchangePublicToken($public_token): void
    {
        $result = $this->plaid->exchangePublicToken(data: [
            'public_token' => $public_token,
        ]);

        if ($this->updating_linked_account_id) {
            // "Update Access Token" flow — replace the existing item's credentials in place
            // rather than creating a duplicate LinkedAccount row (Plaid's update-mode Link flow
            // re-authenticates the SAME item, it doesn't create a new one).
            $linkedAccount = LinkedAccount::findOrFail($this->updating_linked_account_id);
            $this->authorize('update', $linkedAccount);
            $linkedAccount->update([
                'item_id' => $result['item_id'],
                'access_token' => $result['access_token'],
            ]);
            $linkedAccount->updateInfo();
        } else {
            auth()->user()->linkedAccounts()->create([
                'item_id' => $result['item_id'],
                'access_token' => $result['access_token'],
            ])->updateInfo();
        }

        $this->updating_linked_account_id = null;
        $this->redirectRoute('linked-accounts.index');
    }

    public function updateLinkedAccount(): void
    {
        $this->linkedAccounts = auth()->user()->linkedAccounts->toArray();
    }

    public function close(LinkedAccount $linkedAccount): void
    {
        $this->authorize('delete', $linkedAccount);
        $linkedAccount->update(['closed_at' => now()]);
        $this->updateLinkedAccount();
    }

    public function reopen(LinkedAccount $linkedAccount): void
    {
        $this->authorize('update', $linkedAccount);
        $linkedAccount->update(['closed_at' => null]);
        $this->updateLinkedAccount();
    }

    public function updateAutoPull(LinkedAccount $linkedAccount, bool $enabled, int $intervalValue, string $intervalUnit): void
    {
        $this->authorize('update', $linkedAccount);

        if (! in_array($intervalUnit, ['hours', 'days'], true)) {
            throw new InvalidArgumentException('Invalid interval unit.');
        }

        $linkedAccount->update([
            'auto_pull_enabled' => $enabled,
            'auto_pull_interval_value' => max(1, $intervalValue),
            'auto_pull_interval_unit' => $intervalUnit,
        ]);
        $this->updateLinkedAccount();
    }
}

?>
    <x-page-wrapper heading="Linked Institutions" subheading="Manage your linked institutions.">
        <div class="w-full overflow-x-auto">
        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Auto-Pull</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
                @foreach($linkedAccounts as $linkedAccount)
                    <x-table.tr class="{{ $linkedAccount['closed_at'] ? 'opacity-50' : '' }}">
                        <x-table.td>
                            {{ $linkedAccount['provider_name'] }}
                            @if($linkedAccount['closed_at'])
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">(closed {{ \Illuminate\Support\Carbon::parse($linkedAccount['closed_at'])->format('M j, Y') }})</span>
                            @endif
                        </x-table.td>
                        <x-table.td>
                            @unless($linkedAccount['closed_at'])
                            <div
                                class="flex flex-col gap-1"
                                x-data="{
                                    enabled: {{ $linkedAccount['auto_pull_enabled'] ? 'true' : 'false' }},
                                    value: {{ $linkedAccount['auto_pull_interval_value'] }},
                                    unit: '{{ $linkedAccount['auto_pull_interval_unit'] }}',
                                    save() {
                                        $wire.updateAutoPull({{ $linkedAccount['id'] }}, this.enabled, this.value, this.unit);
                                    },
                                }"
                            >
                                <flux:field variant="inline">
                                    <flux:checkbox x-model="enabled" @change="save()" />
                                    <flux:label>Enabled</flux:label>
                                </flux:field>
                                <div class="flex items-center gap-1 text-sm" x-show="enabled" x-cloak>
                                    <span class="text-zinc-500 dark:text-zinc-400">every</span>
                                    <x-input type="number" min="1" x-model.number="value" @change="save()" class="w-16"></x-input>
                                    <flux:select x-model="unit" @change="save()" class="w-24">
                                        <flux:select.option value="hours">hours</flux:select.option>
                                        <flux:select.option value="days">days</flux:select.option>
                                    </flux:select>
                                </div>
                                @if($linkedAccount['last_pulled_at'])
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Last pulled {{ \Illuminate\Support\Carbon::parse($linkedAccount['last_pulled_at'])->diffForHumans() }}</span>
                                @endif
                            </div>
                            @endunless
                        </x-table.td>
                        <x-table.td>
                            <div class="flex gap-2">
                                <x-button icon="list-bullet" title="View Accounts" class="cursor-pointer hover:bg-zinc-200" href="{{ route('linked-accounts.accounts.index', $linkedAccount['id']) }}" wire:navigate></x-button>
                                <x-button icon="arrow-path" title="Update Access Token" class="cursor-pointer !bg-orange-600 hover:!bg-orange-500 dark:!bg-orange-700 dark:!border-orange-700 dark:hover:!bg-orange-600" wire:click="linkAccount({{ $linkedAccount['id'] }})"></x-button>
                                @if($linkedAccount['closed_at'])
                                <x-button icon="arrow-uturn-left" title="Reopen" class="cursor-pointer !bg-green-600 hover:!bg-green-500 dark:!bg-green-700 dark:!border-green-700 dark:hover:!bg-green-600" wire:click="reopen({{ $linkedAccount['id'] }})"></x-button>
                                @else
                                <x-button icon="x-circle" title="Close" class="cursor-pointer !bg-red-600 hover:!bg-red-500 dark:!bg-red-700 dark:!border-red-700 dark:hover:!bg-red-600" wire:click="close({{ $linkedAccount['id'] }})"></x-button>
                                @endif
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>
        </div>

        <div class="w-full sm:w-48">
            <x-button type="primary" wire:click="linkAccount" class="w-full">Link Institution</x-button>
        </div>
    </x-page-wrapper>

<script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>

@script
<script type="text/javascript">
    Livewire.on('triggerPlaid', (event) => {
        var handler = Plaid.create({
            // Create a new link_token to initialize Link
            token: event.link_token,
            onLoad: function() {
                // Optional, called when Link loads
            },
            onSuccess: function(public_token, metadata) {
                // Send the public_token to your app server.
                // The metadata object contains info about the institution the
                // user selected and the account ID or IDs, if the
                // Account Select view is enabled.
                Livewire.dispatch('exchangePublicToken', {
                    public_token: public_token,
                });
            },
            onExit: function(err, metadata) {
                // The user exited the Link flow.
                if (err != null) {
                    // The user encountered a Plaid API error prior to exiting.
                    console.log(err, metadata);
                }
                  // metadata contains information about the institution
                  // that the user selected and the most recent API request IDs.
                  // Storing this information can be helpful for support.
            },
            onEvent: function(eventName, metadata) {
                console.log(eventName, metadata);
                // Optionally capture Link flow events, streamed through
                // this callback as your users connect an Item to Plaid.
                // For example:
                // eventName = "TRANSITION_VIEW"
                // metadata  = {
                //   link_session_id: "123-abc",
                //   mfa_type:        "questions",
                //   timestamp:       "2017-09-14T14:42:19.350Z",
                //   view_name:       "MFA",
                // }
            }
        });
        handler.open();
    });
</script>
@endscript
