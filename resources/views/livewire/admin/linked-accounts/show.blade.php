<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Session;

new class extends Component {

    #[Session]
    public $message = 'hello';

    public function updateMessage(): void
    {
        $this->message = 'world';
    }
}

?>
    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <x-page-heading heading="Linked Accounts" subheading="Account Transactions"></x-page-heading>
    </div>
