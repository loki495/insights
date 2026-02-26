<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('/', 'admin.dashboard')->name('dashboard');
    Route::get('/home', fn() => redirect()->route('dashboard'))->name('home');
    Volt::route('edit', 'admin.edit')->name('edit');

    Route::name('linked-accounts.')->group(function () {
        Volt::route('linked-accounts', 'admin.linked-accounts.index')->name('index');
        // Volt::route('linked-accounts/create', 'admin.linked-accounts.edit')->name('create');
        // Volt::route('linked-accounts/{linkedAccount}/edit', 'admin.linked-accounts.edit')->name('edit');
        Volt::route('linked-accounts/{linkedAccount}/accounts', 'admin.accounts.index')->name('accounts.index');
        Volt::route('linked-accounts/{linkedAccount}/account/{account}/transactions', 'admin.accounts.show')->name('accounts.show');
    });

    Volt::route('original-categories', 'admin.original-categories.index')->name('original-categories.index');
    Volt::route('transactions/create/{account?}', 'admin.transactions.edit')->name('transactions.create');
    Volt::route('transactions/{transaction?}/edit', 'admin.transactions.edit')->name('transactions.edit');

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

require __DIR__.'/auth.php';
