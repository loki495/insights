<?php

declare(strict_types=1);

/**
 * Regression guard: partials/head.blade.php's <title> used to hardcode a literal 'Laravel'
 * fallback instead of reading config('app.name'), so every page's browser tab showed "Laravel"
 * regardless of what a deployer set APP_NAME to.
 */
it('uses config(app.name) for the page title, not a hardcoded "Laravel"', function (): void {
    config(['app.name' => 'Insights']);

    $response = test()->get('/login');

    $response->assertSee('<title>Insights</title>', false);
    $response->assertDontSee('<title>Laravel</title>', false);
});
