@php $iconTrailing = $iconTrailing ??= $attributes->pluck('icon:trailing'); @endphp
@php $iconVariant = $iconVariant ??= $attributes->pluck('icon:variant'); @endphp
@php
// Button should be a square if it has no text contents...
$square ??= $slot->isEmpty();
$iconClasses = Flux::classes($square ? 'size-5!' : 'size-4!');
@endphp

@props([
    'expandable' => false,
    'collapsible' => false,
    'expanded' => true,
    'heading' => null,
    'href' => '',
    'icon' => null,
    'iconDot' => null,
    'iconVariant' => 'outline',
    'iconTrailing' => null,
])

<?php if ($collapsible && $heading) { ?>
    <ui-disclosure {{ $attributes->class('group/disclosure') }} @if ($expanded === true) open @endif data-flux-navlist-group>
        <div class="px-3 py-2 bg-zinc-800/90 rounded-lg mb-2 transition-colors duration-150 hover:bg-zinc-700 dark:hover:bg-zinc-700 flex items-center gap-2" data-flux-navlist-group-heading>
            <button type="button" class="group/disclosure-button shrink-0 cursor-pointer text-zinc-300 hover:text-white" aria-label="Toggle {{ $heading }} accounts">
                <flux:icon.chevron-down class="size-3! hidden group-data-open/disclosure-button:block" />
                <flux:icon.chevron-right class="size-3! block group-data-open/disclosure-button:hidden" />
            </button>

            <div class="min-w-0 flex-1 text-sm text-zinc-100 font-bold leading-none">
                <?php if ($href) { ?>
                    <a href="{{ $href }}" class="block truncate" wire:navigate>{{ $heading }}</a>
                <?php } else { ?>
                    {{ $heading }}
                <?php } ?>
            </div>
        </div>

        <div class="hidden data-open:block" @if ($expanded === true) data-open @endif>
            {{ $slot }}
        </div>
    </ui-disclosure>
<?php } elseif ($expandable && $heading) { ?>
    <ui-disclosure {{ $attributes->class('group/disclosure mb-2') }} @if ($expanded === true) open @endif data-flux-navlist-group>
        <button type="button" class="w-full h-10 lg:h-8 flex justify-between items-center group/disclosure-button mb-[2px] rounded-lg cursor-pointer hover:bg-zinc-800/5 dark:hover:bg-white/[7%] text-zinc-500 hover:text-zinc-800 dark:text-white/80 dark:hover:text-white">
            <div class="flex items-center gap-2 w-full">
                <div class="ps-3 pe-2">
                    <flux:icon.chevron-down class="size-3! hidden group-data-open/disclosure-button:block" />
                    <flux:icon.chevron-right class="size-3! block group-data-open/disclosure-button:hidden" />
                </div>

                <?php if ($icon) { ?>
                    <div class="relative">
                        <?php if (is_string($icon) && $icon !== '') { ?>
                            <flux:icon :$icon :variant="$iconVariant" class="{!! $iconClasses !!}" />
                        <?php } else { ?>
                            {{ $icon }}
                        <?php } ?>

                        <?php if ($iconDot) { ?>
                            <div class="absolute top-[-2px] end-[-2px]">
                                <div class="size-[6px] rounded-full bg-zinc-500 dark:bg-zinc-400"></div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>

                <span class="text-sm font-medium leading-none">
                    <?php if ($href) { ?>
                        <a href="{{ $href }}" class="block" wire:navigate> {{ $heading }}</a>
                    <?php } else { ?>
                        {{ $heading }}
                    <?php } ?>
                </span>
            </div>

            <?php if (is_string($iconTrailing) && $iconTrailing !== '') { ?>
                <flux:icon :icon="$iconTrailing" :variant="$iconVariant" class="size-4! align-end pr-1" />
            <?php } elseif ($iconTrailing) { ?>
                {{ $iconTrailing }}
            <?php } ?>
        </button>

        <div class="relative hidden data-open:block space-y-[2px] pt-1 pb-2 ps-7" @if ($expanded === true) data-open @endif>
            <div class="absolute inset-y-[3px] w-px bg-zinc-200 dark:bg-white/30 start-0 ms-4"></div>

            {{ $slot }}
        </div>
    </ui-disclosure>
<?php } elseif ($heading) { ?>
    <div {{ $attributes->class('block space-y-[2px]') }}>
        <div class="px-3 py-2 bg-zinc-800/90 rounded-lg mb-2 transition-colors duration-150 hover:bg-zinc-700 dark:hover:bg-zinc-700" data-flux-navlist-group-heading>
            <div class="text-sm text-zinc-100 font-bold leading-none"><?php if ($href) { ?><a href="{{ $href }}" class="block" wire:navigate>{{ $heading }}</a><?php } else { ?>{{ $heading }}<?php } ?></div>
        </div>

        <div>
            {{ $slot }}
        </div>
    </div>
<?php } else { ?>
    <div {{ $attributes->class('block space-y-[2px]') }}>
        {{ $slot }}
    </div>
<?php } ?>
