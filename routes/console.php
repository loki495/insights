<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('transactions:pull 1')
    ->withoutOverlapping()
    ->everySixHours();

Schedule::command('transactions:pull 3')
    ->withoutOverlapping()
    ->everySixHours();
