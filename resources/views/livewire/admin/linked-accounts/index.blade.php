<?php

use App\Models\Account;
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
        $this->linkedAccounts = auth()->user()->linkedAccounts()->with('accounts')->get()->map(fn (LinkedAccount $linkedAccount): array => [
            'id' => $linkedAccount->id,
            'provider_name' => $linkedAccount->provider_name,
            'closed_at' => $linkedAccount->closed_at,
            'auto_pull_enabled' => $linkedAccount->auto_pull_enabled,
            'auto_pull_interval_value' => $linkedAccount->auto_pull_interval_value,
            'auto_pull_interval_unit' => $linkedAccount->auto_pull_interval_unit,
            'last_pulled_at' => $linkedAccount->last_pulled_at,
            'last_sync_failed_at' => $linkedAccount->last_sync_failed_at,
            'last_sync_error' => $linkedAccount->last_sync_error,
            'accounts' => $linkedAccount->accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'display_name' => $account->display_name,
                'type' => $account->type,
                'current_balance' => $account->current_balance,
                'available_balance' => $account->available_balance,
            ])->toArray(),
        ])->toArray();
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
        <x-responsive-table
            :items="$linkedAccounts"
            row-view="livewire.admin.linked-accounts.partials.linked-account-table-row"
            card-view="livewire.admin.linked-accounts.partials.linked-account-card"
            empty-message="No linked institutions found"
        >
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Name</x-table.th>
                    <x-table.th>Auto-Pull</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
        </x-responsive-table>

        <div class="w-full sm:w-48">
            <x-button type="primary" wire:click="linkAccount" class="w-full">Link Institution</x-button>
        </div>
    </x-page-wrapper>

@script
<script type="text/javascript">
    // A plain top-level <script src="..."> tag only gets executed by the browser's HTML parser
    // on a genuine hard page load — Livewire's wire:navigate morph inserts this markup via DOM
    // patching instead, which browsers never execute script tags through, leaving `Plaid`
    // permanently undefined for anyone who reaches this page via a soft navigation. Loading it
    // dynamically here, inside the surrounding Blade block Livewire DOES re-run on every mount
    // regardless of navigation type, makes it work the same way whether this page was
    // hard-loaded or soft-navigated to. Confirmed via a real browser test hitting both paths
    // (tests/Browser/PlaidLinkPopupTest.php) — without this, the wire:navigate path threw
    // "Plaid is not defined" 100% of the time, hard-load never did.
    function loadPlaidScript() {
        if (window.Plaid) {
            return Promise.resolve();
        }
        if (!window.__plaidScriptPromise) {
            window.__plaidScriptPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Failed to load the Plaid Link script.'));
                document.head.appendChild(script);
            });
        }

        return window.__plaidScriptPromise;
    }

    Livewire.on('triggerPlaid', (event) => {
        loadPlaidScript().then(() => {
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
    });
</script>
@endscript
