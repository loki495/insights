<?php

use App\Models\LinkedAccount;
use Livewire\Volt\Component;

new class extends Component {

    public ?LinkedAccount $linkedAccount = null;

    public function mount(LinkedAccount $linkedAccount): void
    {
        $this->linkedAccount = $linkedAccount;
    }
}

?>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <x-page-heading heading="Linked Accounts" subheading="Edit Linked Account"></x-page-heading>
    </div>
