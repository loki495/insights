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
        Volt::route('linked-accounts/create', 'admin.linked-accounts.edit')->name('create');
        Volt::route('linked-accounts/{linkedAccount}', 'admin.linked-accounts.show')->name('show');
        Volt::route('linked-accounts/{linkedAccount}/edit', 'admin.linked-accounts.edit')->name('edit');
    });
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
