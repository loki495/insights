<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores money as true integer cents in the database (exact on SQLite, MySQL, and Postgres
 * alike — unlike a decimal-typed column, which SQLite has no real fixed-point storage for at
 * all), while every PHP-side read/write keeps working with a plain dollar value, exactly as
 * before this cast existed.
 *
 * get() deliberately does NOT force a float — PHP's own `/` operator already returns an int for
 * evenly-divisible results and a float otherwise (e.g. `9900/100` is `int(99)`, `12099/100` is
 * `float(120.99)`), which reproduces the exact int/float split this app's SQLite columns already
 * produced by accident before this cast existed (whole-dollar amounts came back as PHP ints,
 * cents-precision amounts as floats). Keeping that instead of always coercing to float avoids
 * churning a large number of existing `toBe($int)` test assertions for no behavioral gain.
 *
 * @implements CastsAttributes<int|float|null, int|float|null>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : $value / 100;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : (int) round($value * 100);
    }
}
