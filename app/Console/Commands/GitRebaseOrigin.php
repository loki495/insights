<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitRebaseOrigin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'git:pull-main';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebase origin/main to main';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $cmd = 'git checkout main && git pull --rebase origin main';

        exec(command: $cmd, result_code: $exitCode);

        if ($exitCode !== 0) {
            $this->error('Rebasing origin/main to main failed');
        }
    }
}
