<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('dashboard', 'admin.dashboard')->name('dashboard');
    Volt::route('edit', 'admin.edit')->name('edit');

    Route::name('linked-accounts.')->group(function () {
        Volt::route('linked-accounts', 'admin.linked-accounts.index')->name('index');
        // Volt::route('linked-accounts/create', 'admin.linked-accounts.edit')->name('create');
        // Volt::route('linked-accounts/{linkedAccount}/edit', 'admin.linked-accounts.edit')->name('edit');
        Volt::route('linked-accounts/{linkedAccount}/accounts', 'admin.accounts.index')->name('accounts.index');
        Volt::route('linked-accounts/{linkedAccount}/account/{account}/transactions', 'admin.accounts.show')->name('accounts.show');
    });

    Volt::route('categories', 'admin.categories.index')->name('categories.index');

    Route::name('reports.')->group(function () {
        Volt::route('reports', 'admin.reports.index')->name('index');
        Volt::route('reports/category/{category?}', 'admin.reports.category.index')->name('category.index');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::get('/test', function () {
    $account = \App\Models\LinkedAccount::first()->updateInfo();
    dd($account);
});

require __DIR__.'/auth.php';
