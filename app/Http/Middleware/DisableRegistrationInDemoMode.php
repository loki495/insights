<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo mode only (config('app.demo_mode')): the demo build shares one seeded login
 * (test@example.com / password, see DemoDataSeeder) rather than letting visitors create real
 * accounts on the public demo. Applied to the 'register' route only (see routes/auth.php) — a
 * runtime check rather than conditionally registering the route at boot, so demo mode can be
 * toggled per-request in tests without needing the application rebuilt.
 */
class DisableRegistrationInDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('app.demo_mode'), 404);

        return $next($request);
    }
}
