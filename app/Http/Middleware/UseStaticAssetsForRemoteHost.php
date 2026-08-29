<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseStaticAssetsForRemoteHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getHost(), config('app.static_asset_hosts'), true)) {
            return $next($request);
        }

        $vite = app(Vite::class);
        $originalHotFile = $vite->hotFile();
        $originalSecureCookie = config('session.secure');

        $vite->useHotFile(public_path('hot-static'));
        config(['session.secure' => true]);

        try {
            return $next($request);
        } finally {
            $vite->useHotFile($originalHotFile);
            config(['session.secure' => $originalSecureCookie]);
        }
    }
}
