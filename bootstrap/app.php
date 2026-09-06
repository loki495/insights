<?php

use App\Http\Middleware\ResolveDemoDatabase;
use App\Http\Middleware\UseStaticAssetsForRemoteHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust Traefik so the request's scheme/host reflect the original client
        // connection (https via the ac495.net domain) instead of the plain-HTTP
        // hop Traefik makes to this container — without this, asset() URLs come
        // back as http:// and get blocked as mixed content on the https page.
        $middleware->trustProxies(at: '*');

        $middleware->web(prepend: [
            UseStaticAssetsForRemoteHost::class,
        ]);

        // Demo mode only (config('app.demo_mode')) - no-op otherwise. Appended (not
        // prepended) so it runs after session start but before any route-specific
        // middleware (e.g. 'auth') that queries the users table.
        $middleware->appendToGroup('web', [
            ResolveDemoDatabase::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
