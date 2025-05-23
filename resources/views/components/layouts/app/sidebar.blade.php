<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="sidebar border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 resize-x pr-4">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="mr-5 flex items-center space-x-2" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>

                <flux:navlist.group heading="Linked Accounts" :href="route('linked-accounts.index')" expandable :expanded="request()->routeIs('linked-accounts.*')" expanded>
                    {{-- <flux:navlist.item icon="pencil" :href="route('linked-accounts.create')" :current="request()->routeIs('linked-accounts.create')" wire:navigate>{{ __('Add Linked Account') }}</flux:navlist.item> --}}
                    @foreach (auth()->user()->linkedAccounts()->with('accounts')->get() as $linkedAccount)
                    <flux:navlist.group :heading="$linkedAccount->provider_name" :href="route('linked-accounts.accounts.index', $linkedAccount)">
                        @foreach ($linkedAccount->accounts as $account)
                        <flux:navlist.item :badge="$account->transactions()->count()" badge-class="self-start" :href="route('linked-accounts.accounts.show', [ $linkedAccount, $account ])" :current="request()->routeIs('linked-accounts.account.show', [$linkedAccount, $account])" wire:navigate class="w-full !p-4">
                            <div class="font-semibold">{{ $account->name }}</div>
                            <div class="text-xs dark:!text-zinc-400">{!! currency($account->current_balance, flat: true) !!}</div>
                        </flux:navlist.item>
                        @endforeach
                    </flux:navlist.group>
                    @endforeach
                </flux:navlist.group>

                <flux:navlist.item icon="list-bullet" :href="route('original-categories.index')" :current="request()->routeIs('original-categories.*')" wire:navigate>{{ __('Original Categories') }}</flux:navlist.item>

                <flux:navlist.item icon="list-bullet" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>{{ __('Categories') }}</flux:navlist.item>

                <flux:navlist.group class="!cursor-pointer" heading="Reports" expandable :expanded="request()->routeIs('reports.*')">
                    <flux:navlist.item icon="list-bullet" :href="route('reports.category.index')" :current="request()->routeIs('reports.category.*')" wire:navigate>{{ __('By Categories') }}</flux:navlist.item>
                </flux:navlist.group>

            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:button class="!absolute right-0 top-0" x-data x-on:click="$flux.dark = ! $flux.dark" icon="moon" variant="subtle" aria-label="Toggle dark mode" />

        {{ $slot }}

        @fluxScripts
<script>
        window.addEventListener('livewire:init', function () {
            observeSidebarWidth();
            updateSidebarWidth();
        })

        window.addEventListener('livewire:navigated', function () {
            observeSidebarWidth();
            updateSidebarWidth();
        })

        function observeSidebarWidth() {
            const sidebar = document.querySelector('.sidebar');

            // Debounce function
            function debounce(fn, delay) {
                let timeoutId;
                return (...args) => {
                    clearTimeout(timeoutId);
                    timeoutId = setTimeout(() => fn.apply(this, args), delay);
                };
            }

            const debouncedResize = debounce(entries => {
                for (let entry of entries) {
                    const { width, height } = entry.contentRect;
                    if (width === 0) return;
                    localStorage.setItem('sidebar_width', width);
                    //console.log('Sidebar width SET:', width);
                }
            }, 100);

            const observer = new ResizeObserver(debouncedResize);

            observer.observe(sidebar);
            //console.log('Observing sidebar width');
        }

        function updateSidebarWidth() {
            const sidebar = document.querySelector('.sidebar');
            const sidebar_width = localStorage.getItem('sidebar_width');
            if (sidebar_width) {
                // sidebar.style.width = `${sidebar_width}px`;
                //console.log('Sidebar width GET:', sidebar_width);
            }
        }
</script>
    </body>
</html>
