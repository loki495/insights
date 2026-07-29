<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Models\Transaction;

function moneyCast(): MoneyCast
{
    return new MoneyCast;
}

it('converts stored cents to dollars on get()', function (): void {
    $cast = moneyCast();
    $model = new Transaction;

    expect($cast->get($model, 'amount', 10527, []))->toBe(105.27)
        ->and($cast->get($model, 'amount', -1599, []))->toBe(-15.99)
        ->and($cast->get($model, 'amount', 9900, []))->toBe(99)
        ->and($cast->get($model, 'amount', 0, []))->toBe(0)
        ->and($cast->get($model, 'amount', null, []))->toBeNull();
});

it('converts dollars to integer cents on set()', function (): void {
    $cast = moneyCast();
    $model = new Transaction;

    expect($cast->set($model, 'amount', 105.27, []))->toBe(10527)
        ->and($cast->set($model, 'amount', -15.99, []))->toBe(-1599)
        ->and($cast->set($model, 'amount', 99, []))->toBe(9900)
        ->and($cast->set($model, 'amount', 0, []))->toBe(0)
        ->and($cast->set($model, 'amount', null, []))->toBeNull();
});

it('rounds away float noise beyond 2 decimal places on set()', function (): void {
    // Simulates the kind of float imprecision a real Plaid payload or prior arithmetic could
    // produce (e.g. 105.26999999999998) — set() must not truncate this down to the wrong cent.
    $cast = moneyCast();
    $model = new Transaction;

    expect($cast->set($model, 'amount', 105.26999999999998, []))->toBe(10527)
        ->and($cast->set($model, 'amount', 0.1 + 0.2, []))->toBe(30); // classic float-noise case
});

it('round-trips through get() and set() without drift', function (): void {
    $cast = moneyCast();
    $model = new Transaction;

    foreach ([105.27, -15.99, 99.0, 0.01, -0.01, 1234.56] as $dollars) {
        $cents = $cast->set($model, 'amount', $dollars, []);
        expect($cast->get($model, 'amount', $cents, []))->toEqual($dollars);
    }
});
