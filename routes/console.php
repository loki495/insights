<?php

use Illuminate\Support\Facades\Schedule;

// Runs hourly and lets the command itself decide what's actually due — each linked institution
// has its own auto_pull_enabled + interval setting (see LinkedAccount::isAutoPullDue()), so this
// just needs to be at least as frequent as the shortest interval a user can pick (1 hour).
Schedule::command('transactions:pull')
    ->withoutOverlapping()
    ->hourly();

// Demo mode only - the ->when() checks make these a no-op on every normal (non-demo)
// deployment of this same image, same convention as config('app.demo_mode') everywhere else.
// Unlike homie's equivalent (a static, non-date-sensitive demo dataset whose template is never
// regenerated), insights' DemoDataSeeder anchors transaction dates to "now" at seed time (see
// commit 8b577ca), so the template itself must also be rebuilt daily, not just have stale
// per-visitor files cleaned up.
Schedule::command('demo:build-template')
    ->daily()
    ->when(fn (): bool => (bool) config('app.demo_mode'));

Schedule::command('demo:cleanup')
    ->daily()
    ->when(fn (): bool => (bool) config('app.demo_mode'));
