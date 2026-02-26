<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('transactions:pull')
    ->withoutOverlapping()
    ->daysOfMonth([1, 10, 20]);
