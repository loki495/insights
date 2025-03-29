<?php


use App\Models\LinkedAccount;
use App\Services\Plaid\PlaidService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {

    public array $linkedAccounts;

    public string $environment = '';

    private $plaid_instance;

    protected $listeners = [
        'exchangePublicToken' => '$refresh'
    ];

    public function mount() {
        $this->linkedAccounts = auth()->user()->linkedAccounts->toArray();
    }

    #[Computed]
    private function plaid() {

        if (! $this->plaid_instance) {
            $this->plaid_instance = plaid();
        }

        return $this->plaid_instance;
    }

    public function linkAccount() {
        $response = $this->plaid->getLinkToken(data: [
            'client_name' => 'Insights',
            'products' => ['auth', 'transactions'],
            'country_codes' => ['US'],
            'language' => 'en',
            'user' => [
                'client_user_id' => (string)auth()->user()->id,
                //'phone_number' => '415-555-0012',
            ]
        ]);

        $link_token = $response['link_token'];

        $this->dispatch('triggerPlaid', link_token: $link_token);
    }

    #[On('exchangePublicToken')]
    public function exchangePublicToken($public_token) {
        $result = $this->plaid->exchangePublicToken(data: [
            'public_token' => $public_token
        ]);

        auth()->user()->linkedAccounts()->create([
            'item_id' => $result['item_id'],
            'access_token' => $result['access_token'],
        ])->updateInfo();
    }
}

?>
    <x-page-wrapper heading="Linked Institutions" subheading="Manage your linked institutions.">
        <x-table>
            <x-slot name="head">
                <x-table.tr>
                    <x-table.th>Name</x-table.th>
                    <x-table.th></x-table.th>
                </x-table.tr>
            </x-slot>
            <x-slot name="body">
                @foreach($linkedAccounts as $linkedAccount)
                    <x-table.tr>
                        <x-table.td>{{ $linkedAccount['provider_name'] }}</x-table.td>
                        <x-table.td>
                            <div class="flex gap-2">
                                <x-button icon="list-bullet" title="View Accounts" class="cursor-pointer hover:bg-zinc-200" href="{{ route('linked-accounts.accounts.index', $linkedAccount['id']) }}" wire:navigate></x-button>
                                <x-button icon="trash" title="Unlink" class="cursor-pointer !bg-red-600 hover:!bg-red-500 dark:!bg-red-700 dark:!border-red-700 dark:hover:!bg-red-600" wire:click="delete({{ $linkedAccount['id'] }})"></x-button>
                            </div>
                        </x-table.td>
                    </x-table.tr>
                @endforeach
            </x-slot>
        </x-table>

        <div class="w-48">
            <x-button type="primary" wire:click="linkAccount" class="w-full">Link Institution</x-button>
        </div>
    </x-page-wrapper>

<script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>

<script type="text/javascript">
document.addEventListener('livewire:init', () => {

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
});
</script>
