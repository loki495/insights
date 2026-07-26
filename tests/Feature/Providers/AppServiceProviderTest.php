<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

/**
 * Regression guard: configureUrl() used to force https for any non-local APP_ENV regardless of
 * what APP_URL actually was, breaking asset loading (Vite build output, Livewire, Flux) entirely
 * for anyone testing a real production build locally over plain http — including this app's own
 * README quick start (APP_ENV=production, default APP_URL=http://localhost). Confirmed live: a
 * real docker-compose.prod.yml build/run with that exact combination served a 200 OK page with
 * every asset request failing (ERR_SSL_PROTOCOL_ERROR) until this fix.
 */
it('forces https when APP_URL is https, in a non-local environment', function (): void {
    config(['app.url' => 'https://example.com']);
    app()->detectEnvironment(fn (): string => 'production');

    URL::forceScheme(''); // reset any scheme forced by a previous test/boot
    (new AppServiceProvider(app()))->configureUrl();

    expect(url('/'))->toStartWith('https://');
});

it('does not force https when APP_URL is plain http, even in a non-local environment', function (): void {
    config(['app.url' => 'http://localhost']);
    app()->detectEnvironment(fn (): string => 'production');

    URL::forceScheme(''); // reset any scheme forced by a previous test/boot
    (new AppServiceProvider(app()))->configureUrl();

    expect(url('/'))->toStartWith('http://');
});
