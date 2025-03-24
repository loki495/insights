<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitRebaseCurrent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'git:rebase-current';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebase current to main';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $cmd = 'git branch --show-current';

        $output = [];
        exec(command: $cmd, output: $output, result_code: $exitCode);
        $branch = $output[0];

        if ($branch === 'main') {
            $this->error('Cannot rebase main to main');

            return;
        }

        $cmd = 'git rebase main';
        exec(command: $cmd, result_code: $exitCode);

        if ($exitCode !== 0) {
            $this->error("Rebasing main to current branch ({$branch}) failed");
        }
    }
}
