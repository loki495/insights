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

    Volt::route('original-categories', 'admin.original-categories.index')->name('original-categories.index');

    Route::name('categories.')->group(function () {
        Volt::route('/categories', 'admin.categories.index')->name('index');
        Volt::route('/categories/create', 'admin.categories.edit')->name('create');
        Volt::route('/categories/{category}', 'admin.categories.edit')->name('show');
        Volt::route('/categories/{category}/edit', 'admin.categories.edit')->name('edit');
    });

    Route::name('reports.')->group(function () {
        Volt::route('reports', 'admin.reports.index')->name('index');
        Volt::route('reports/category/{category?}', 'admin.reports.category.index')->name('category.index');
        Volt::route('reports/category/', 'admin.reports.category.index')->name('category.index.all');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::get('/test', function () {
    $path = realpath(__DIR__.'/../..') . '/w';
    $d = json_decode(file_get_contents($path));
    dd($d->schema);
});

require __DIR__.'/auth.php';
