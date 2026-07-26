<?php

declare(strict_types=1);
use NunoMaduro\Essentials\Configurables\ForceScheme;

/**
 * nunomaduro/essentials bundles a set of opinionated Laravel defaults, applied automatically
 * unless explicitly overridden here. Only the one below needed overriding; every other
 * configurable keeps the package's own default (see vendor/nunomaduro/essentials/config/essentials.php
 * for the full list and their descriptions).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS scheme
    |--------------------------------------------------------------------------
    |
    | Disabled — handled instead by App\Providers\AppServiceProvider::configureUrl(), which only
    | forces https when APP_URL itself is https, rather than unconditionally for any non-local
    | environment. Essentials' own unconditional default broke asset loading (Vite build output,
    | Livewire, Flux) entirely for anyone testing a real production build locally over plain
    | http — including this app's own README quick start (APP_ENV=production, default
    | APP_URL=http://localhost). Confirmed live: a real docker-compose.prod.yml build/run with
    | that exact combination served a 200 OK page with every asset request failing
    | (ERR_SSL_PROTOCOL_ERROR) until this was disabled.
    |
    */

    ForceScheme::class => false,

];
