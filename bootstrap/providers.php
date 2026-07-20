<?php

use App\Providers\AppServiceProvider;
use App\Providers\VoltServiceProvider;

// barryvdh/laravel-debugbar is dev-only (require-dev) and ships its own package
// auto-discovery — registering it explicitly here broke `composer install --no-dev`
// entirely (production), since this array loads unconditionally regardless of whether the
// package is actually installed. Let auto-discovery handle it: present in dev, absent in prod.
return [
    AppServiceProvider::class,
    VoltServiceProvider::class,
];
