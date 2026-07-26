<?php

declare(strict_types=1);

use App\Models\LinkedAccount;
use App\Models\User;

/**
 * Regression guard: the Auto-Pull column's "every N hours/days" row (flux input + select, no
 * shrink-0) had no real mobile layout at all — the page was a bare <x-table> in a horizontally
 * scrolling wrapper, so on a narrow viewport the flex row got crushed down toward min-content,
 * hiding the interval value entirely. Migrated onto <x-responsive-table> (matching
 * transactions/categories/accounts) for a real stacked card below `sm`. Also hit a genuine Flux
 * quirk while fixing this: flux:input's wrapper bakes in an unconditional `w-full` class (unlike
 * flux:select, which wraps its own default in a zero-specificity `:where()` so overrides always
 * win) — a plain `w-16` utility loses the cascade depending on Tailwind's internal generation
 * order, so the interval input needs `!w-16` to actually win.
 */
it('shows the auto-pull interval input at full width on a mobile viewport', function (): void {
    $user = User::factory()->create();
    $linkedAccount = LinkedAccount::factory()->for($user)->create([
        'item_id' => 'item_'.uniqid(),
        'access_token' => 'access_'.uniqid(),
        'provider_name' => 'Test Bank',
        'auto_pull_enabled' => true,
        'auto_pull_interval_value' => 24,
        'auto_pull_interval_unit' => 'hours',
    ]);

    test()->actingAs($user);

    visit('/linked-accounts')
        ->resize(390, 844)
        ->assertSee('Test Bank')
        ->assertScript('document.body.scrollWidth <= window.innerWidth')
        ->assertScript(<<<'JS'
            (() => {
                const card = Array.from(document.querySelectorAll('div')).find(
                    (el) => el.className.includes('sm:hidden') && el.className.includes('flex-col')
                );
                const input = card.querySelector('input[type=number]');
                return input.getBoundingClientRect().width >= 60;
            })()
            JS);
});
