<?php

use App\Http\Middleware\ResolveDemoDatabase;
use App\Http\Middleware\UseStaticAssetsForRemoteHost;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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

        // Registration order above is NOT execution order: Laravel's built-in middleware
        // priority list forces 'auth' (Illuminate\Auth\Middleware\Authenticate, via the
        // AuthenticatesRequests contract) to run before SubstituteBindings regardless of where
        // it's registered - which put it before ResolveDemoDatabase too, since ResolveDemoDatabase
        // wasn't in the priority list at all. 'guest' (RedirectIfAuthenticated) doesn't implement
        // that contract, so it wasn't reordered and correctly ran after ResolveDemoDatabase. Net
        // effect: a logged-in demo visitor was seen as authenticated by /login's guest check
        // (correct, post-swap database) but unauthenticated by /'s auth check (wrong, pre-swap
        // database) - an infinite redirect loop. Must target the *contract* FQCN here, not
        // Authenticate::class - addToMiddlewarePriorityBefore/After match by exact string only
        // (no interface resolution, unlike the actual per-request sort), and the contract is what
        // literally appears in the framework's default priority list.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveDemoDatabase::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
