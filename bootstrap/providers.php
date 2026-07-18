<?php

use App\Providers\AppServiceProvider;
use App\Providers\VoltServiceProvider;
use Fruitcake\LaravelDebugbar\ServiceProvider;

return [
    AppServiceProvider::class,
    VoltServiceProvider::class,
    ServiceProvider::class,
];
