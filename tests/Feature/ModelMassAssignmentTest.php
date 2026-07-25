<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\LinkedAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\MassAssignmentException;

/**
 * Regression tests for the removal of the global Model::unguard() (previously made $fillable on
 * every model pure decoration, with zero real mass-assignment protection). Each of these throws
 * MassAssignmentException specifically because AppServiceProvider::configureModels() calls
 * Model::preventSilentlyDiscardingAttributes(true) outside production — without that, an
 * unexpected field would just be silently dropped instead of raising anything.
 */
it('rejects mass-assigning a field not in Account::$fillable', function (): void {
    expect(fn () => Account::create(['not_a_real_column' => 'x']))
        ->toThrow(MassAssignmentException::class);
});

it('rejects mass-assigning a field not in Category::$fillable', function (): void {
    expect(fn () => Category::create(['not_a_real_column' => 'x']))
        ->toThrow(MassAssignmentException::class);
});

it('rejects mass-assigning a field not in LinkedAccount::$fillable', function (): void {
    expect(fn () => LinkedAccount::create(['not_a_real_column' => 'x']))
        ->toThrow(MassAssignmentException::class);
});

it('rejects mass-assigning a field not in Transaction::$fillable', function (): void {
    expect(fn () => Transaction::create(['not_a_real_column' => 'x']))
        ->toThrow(MassAssignmentException::class);
});
