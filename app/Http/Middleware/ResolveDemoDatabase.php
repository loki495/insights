<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo mode only (config('app.demo_mode')): gives each visitor their own private copy of the
 * demo dataset instead of one database shared by every concurrent visitor. Unlike homie's
 * equivalent, insights keeps its real login form — every visitor logs in with the same seeded
 * demo credentials (test@example.com / password, from DemoDataSeeder), but their own
 * transactions/rules/categories are isolated to their own session's file copy. Deliberately
 * keyed by a dedicated cookie rather than Laravel's own session ID, so this never depends on
 * the session already being resolved (SESSION_DRIVER must be `file` or `cookie`, not the
 * `database` default, in a demo deployment — see .env.example). See
 * .ai/plans/2026-09-06-demo-sites-and-cd (outside this repo) for the full design.
 *
 * Deliberately switches `database.default` to a dedicated connection name (CONNECTION_NAME)
 * rather than overwriting `database.connections.sqlite.database` in place (homie's approach):
 * doing the latter and then `DB::purge('sqlite')`-ing it corrupts Laravel's shared in-memory
 * SQLite connection during testing — `RefreshDatabase` never expects the literal `sqlite`
 * connection it manages to be disconnected mid-suite, and purging it cascades "table already
 * exists" / "cannot VACUUM from within a transaction" failures into unrelated tests that never
 * even touch demo mode (confirmed empirically while writing this middleware's own tests).
 * Switching the *default connection name* instead means the real `sqlite` connection (and
 * whatever `RefreshDatabase` is doing with it) is never touched at all.
 */
class ResolveDemoDatabase
{
    private const string COOKIE_NAME = 'demo_instance_id';

    public const string CONNECTION_NAME = 'sqlite_demo';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.demo_mode')) {
            return $next($request);
        }

        $demoId = $request->cookie(self::COOKIE_NAME);

        if (! is_string($demoId) || ! preg_match('/^[a-zA-Z0-9]{40}$/', $demoId)) {
            $demoId = Str::random(40);
            Cookie::queue(self::COOKIE_NAME, $demoId, 60 * 24 * 30);
        }

        $dbPath = rtrim((string) config('app.demo_db_storage_path'), '/')."/{$demoId}.sqlite";

        if (! file_exists($dbPath)) {
            $template = config('app.demo_db_template_path');

            if (! is_string($template) || ! file_exists($template)) {
                abort(500, 'Demo database template is missing.');
            }

            if (! is_dir(dirname($dbPath))) {
                mkdir(dirname($dbPath), recursive: true);
            }

            copy($template, $dbPath);
        }

        config([
            'database.connections.'.self::CONNECTION_NAME => [
                'driver' => 'sqlite',
                'database' => $dbPath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => self::CONNECTION_NAME,
        ]);

        // Harmless no-op if this connection name was never resolved yet (the common case — a
        // fresh PHP process per request); guards against a stale cached connection from an
        // earlier visitor if this ever runs under a long-lived worker (Octane, queue) instead.
        DB::purge(self::CONNECTION_NAME);

        return $next($request);
    }
}
