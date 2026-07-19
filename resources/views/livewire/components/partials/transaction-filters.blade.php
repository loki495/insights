<div class="flex flex-col grow min-w-0 w-full rounded-xl bg-zinc-100 dark:bg-white/10" x-data="{ filtersOpen: false }">
    <button type="button" @click="filtersOpen = !filtersOpen" class="cursor-pointer select-none p-2 font-medium w-full text-left flex items-center gap-1">
        <span class="transition-transform" :class="{ 'rotate-90': filtersOpen }">
            <flux:icon.chevron-right class="size-3!" />
        </span>
        Filters
    </button>
    <div x-show="filtersOpen" x-cloak class="flex flex-col gap-4 items-start w-full p-2 pt-0">
    <!-- filters -->
    <div class="flex flex-col gap-4 items-start w-full">
        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="search">Search</label>
            <x-input type="text" wire:model.live.debounce="search" placeholder="Search" class="w-full" clearable></x-input>
        </div>

        <div class="flex gap-4 items-center w-full">
            <flux:field variant="inline">
                <flux:checkbox wire:model.live.debounce="only_uncategorized" />
                <flux:label>Only show transactions without categories</flux:label>
            </flux:field>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="type">Type</label>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach(['income' => 'Income', 'expense' => 'Expense', 'transfer' => 'Transfer', 'adjustment' => 'Adjustment'] as $type_value => $type_label)
                <flux:field variant="inline">
                    <flux:checkbox wire:model.live="type_filters" value="{{ $type_value }}" />
                    <flux:label>{{ $type_label }}</flux:label>
                </flux:field>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="amount">Amount</label>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <x-input type="number" step="0.01" min="0" wire:model.live.debounce="amount_min" placeholder="Min" class="w-full sm:w-32"></x-input>
                <span class="text-zinc-500 dark:text-zinc-400">–</span>
                <x-input type="number" step="0.01" min="0" wire:model.live.debounce="amount_max" placeholder="Max" class="w-full sm:w-32"></x-input>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="search">Original Category</label>
            <flux:select wire:model.live="original_category_id" clearable>
                <flux:select.option value="0">-- All Original Categories --</flux:select.option>
                @foreach(\App\Models\OriginalCategory::all()->sortBy('full_path') as $category_option)
                <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->full_path }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="search">Category</label>
            <flux:select wire:model.live="category_id" clearable>
                <flux:select.option value="0">-- All Categories --</flux:select.option>
                @foreach($this->categories as $category_option)
                <flux:select.option value="{{ $category_option->id }}" wire:key="{{ $category_option->id }}">{{ $category_option->fullName }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full">
            <label for="date">Date</label>
            <x-input type="datetime-local" wire:model.live="date_from_local" placeholder="From" class="w-full"></x-input>
            <x-input type="datetime-local" wire:model.live="date_to_local" placeholder="To" class="w-full"></x-input>
        </div>
    </div>

    @if ($allow_accounts)
    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 w-full" x-data="{ accountsOpen: false }">
        <label for="account">Account</label>
        <div class="relative w-full" @click.outside="accountsOpen = false">
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
    @endif

    </div>
</div>
