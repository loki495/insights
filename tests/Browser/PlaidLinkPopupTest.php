<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Plaid\PlaidService;

/**
 * Regression coverage for the historical wire:navigate bug documented in CLAUDE.md: a
 * Livewire.on(...) listener registered inside a `document.addEventListener('livewire:init', ...)`
 * guard only ever runs once, on a hard page load — if the page is reached via wire:navigate soft
 * navigation without a prior hard load, the listener never registers. linked-accounts/index.blade.php
 * was fixed to use @script/@endscript specifically to survive both cases; these tests prove it,
 * rather than just trusting the fix stays in place.
 *
 * Doesn't engage with the real Plaid Link flow (no real credentials needed, nothing to complete)
 * — getLinkToken() is faked with a syntactically-plausible but bogus token. Confirmed empirically
 * first (via a live MCP-driven browser session) that Plaid's real, CDN-loaded SDK still renders
 * the #plaid-link-iframe-1 iframe shell even with a bogus token — it only rejects the token
 * asynchronously, server-side, well after the iframe already exists — so asserting on that iframe
 * is a reliable, hermetic signal that our own click → dispatch('triggerPlaid') → Plaid.create().open()
 * wiring actually fired, without depending on real Plaid credentials being present in CI.
 */
function fakePlaidLinkToken(): void
{
    $mock = Mockery::mock(PlaidService::class);
    $mock->shouldReceive('getLinkToken')->once()->andReturn(['link_token' => 'link-sandbox-fake-token-for-browser-test']);
    // plaid()/PlaidService is always resolved via app(PlaidService::class, ['environment' => ...]) — a
    // non-empty $parameters array, which makes the container skip an instance()-bound mock and build a
    // real one instead. A plain bind() doesn't have that problem (see tests/Feature/PlaidLinkFlowTest.php).
    app()->bind(PlaidService::class, fn () => $mock);
}

it('shows the real Plaid Link popup after a hard page load', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    fakePlaidLinkToken();

    visit('/linked-accounts')
        // A brief settle wait before interacting — clicking immediately after visit() can race
        // Alpine/Livewire's own async initialization on a freshly-navigated page.
        ->wait(0.5)
        ->click('Link Institution')
        ->wait(0.5)
        ->assertPresent('iframe[src*="cdn.plaid.com"]');
});

it('shows the real Plaid Link popup after arriving via a wire:navigate soft navigation', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    fakePlaidLinkToken();

    // A brand new user with zero linked institutions sees a dashboard empty-state that links to
    // Linked Accounts via wire:navigate — exactly the kind of soft-nav arrival the historical bug
    // depended on (no prior hard load of linked-accounts/index in this browser session at all).
    visit('/')
        ->wait(0.5)
        ->click('Go to Linked Accounts')
        ->wait(0.5)
        ->click('Link Institution')
        ->wait(0.5)
        ->assertPresent('iframe[src*="cdn.plaid.com"]');
});
