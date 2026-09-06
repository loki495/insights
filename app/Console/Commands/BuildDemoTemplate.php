<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\ResolveDemoDatabase;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Builds (or rebuilds) the demo database template that ResolveDemoDatabase copies for every
 * new demo visitor - see config('app.demo_db_template_path'). Unlike homie's equivalent
 * (never regenerated - its demo data isn't date-sensitive), this MUST be safe to run
 * repeatedly on a schedule: DemoDataSeeder anchors its transaction dates to "now" at seed
 * time (fixed in commit 8b577ca), so a template built once and never rebuilt would show
 * increasingly stale/implausible dates. See routes/console.php for the daily schedule.
 *
 * Builds against a dedicated connection (ResolveDemoDatabase::CONNECTION_NAME), not the
 * app's real `sqlite` connection - see that middleware's docblock for why mutating `sqlite`
 * directly is avoided even outside of tests, for consistency between the two.
 */
class BuildDemoTemplate extends Command
{
    #[\Override]
    protected $signature = 'demo:build-template';

    #[\Override]
    protected $description = 'Build (or rebuild) the demo database template (migrate + seed via DemoDataSeeder)';

    public function handle(): int
    {
        $path = config('app.demo_db_template_path');

        if (! is_string($path) || $path === '') {
            $this->error('app.demo_db_template_path is not configured.');

            return self::FAILURE;
        }

        $connection = ResolveDemoDatabase::CONNECTION_NAME;

        config([
            'database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => $connection,
        ]);
        DB::purge($connection);

        $this->info("Building demo template at {$path}");

        Artisan::call('migrate:fresh', ['--force' => true, '--database' => $connection], $this->output);
        Artisan::call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true, '--database' => $connection], $this->output);

        $this->info('Demo template built.');

        return self::SUCCESS;
    }
}
