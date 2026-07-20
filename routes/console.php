<?php

use Illuminate\Support\Facades\Schedule;

// Runs hourly and lets the command itself decide what's actually due — each linked institution
// has its own auto_pull_enabled + interval setting (see LinkedAccount::isAutoPullDue()), so this
// just needs to be at least as frequent as the shortest interval a user can pick (1 hour).
Schedule::command('transactions:pull')
    ->withoutOverlapping()
    ->hourly();
