<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Deletes per-visitor demo database copies (see ResolveDemoDatabase) older than the retention
 * window. The template itself is never touched here - see demo:build-template for the daily
 * rebuild that keeps its seeded dates current. Intended to run on a schedule (daily) in the
 * demo deployment only; harmless no-op if the demo-dbs directory doesn't exist.
 */
class CleanupDemoDatabases extends Command
{
    #[\Override]
    protected $signature = 'demo:cleanup {--hours=24 : Delete per-visitor files older than this many hours}';

    #[\Override]
    protected $description = 'Delete stale per-visitor demo database copies';

    public function handle(): int
    {
        $dir = config('app.demo_db_storage_path');

        if (! is_string($dir) || ! is_dir($dir)) {
            $this->info('No demo-dbs directory yet - nothing to clean.');

            return self::SUCCESS;
        }

        $maxAgeSeconds = ((int) $this->option('hours')) * 3600;
        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        foreach (glob($dir.'/*.sqlite') ?: [] as $file) {
            if ((filemtime($file) ?: 0) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} stale demo database file(s).");

        return self::SUCCESS;
    }
}
