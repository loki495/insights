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

Route::get('/test', function () {
    $path = storage_path('app/private/w');
    $d = json_decode(file_get_contents($path));
    dd((array)$d->schema);
});

Route::get('/import', function () {
    die('no');
    $path = request()->path ?? '';
    if ($path[0] === '~') {
        $path = str_replace('~', trim(`echo ~`, "\n"), $path);
    }

    $count = 1;
    $account_id = 2;

    echo '<pre>';

    // Ally CSV format
    $fp = fopen($path, 'r');
    fgetcsv($fp);
    while ($line = fgetcsv($fp)) {
        if ($line[0] == '2025-02-18') continue;
        $date_str = $line[0] . ' ' . $line[1];
        $date = \Carbon\Carbon::parse($date_str);
        $name = trim($line[4]);
        $amount = str_replace(['$', ',', '.'], '', $line[2]);
        $amount = ($line[3] == 'Deposit' ? -1 : 1) * floatval(trim($amount)) / 100;

        $transaction = \App\Models\Transaction::query()
            ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
            ->where('name', 'like', $name . '%')
            ->where('amount', $amount)
            //->ddRawSql()
            ->first();

        if (!$transaction) {
            preg_match('/Conf# ([0-9a-zA-Z]\b)/', $name, $name_fields);
            if ($name_fields[1] ?? false) {
                $conf = trim($name_fields[1]);
                $transaction = \App\Models\Transaction::query()
                    ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
                    ->where('name', 'like', "%$conf%")
                    ->where('amount', $amount)
                    ->first();
            }
        }

        if (!$transaction) {
            echo "$count - $date $name $amount\n";
            $transaction = \App\Models\Transaction::query()
                ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
                ->where('name', 'like', $name . '%')
                ->where('amount', $amount)
                //->dumpRawSql()
            ;

            if (0) {
                dd('saving', [
                    'account_id' => $account_id,
                    'created_at' => $date,
                    'currency' => 'USD',
                    'name' => $name,
                    'amount' => $amount,
                ]);
            }

            $transaction = \App\Models\Transaction::create([
                'account_id' => $account_id,
                'created_at' => $date,
                'currency' => 'USD',
                'name' => $name,
                'amount' => $amount,
            ]);

            echo '<hr>';
            $count++;
        }

        //$transaction->categories()->attach(1);
    }

    /*
    BofA text format

    $lines = file($path);
    foreach ($lines as $line) {
        preg_match_all('#^(\d\d/\d\d/\d\d\d\d) (.*?) [- ](.*)? (.*)\r$#', $line, $fields, PREG_SET_ORDER);
        [$all, $date, $name, $amount] = $fields[0];
        $date = \Carbon\Carbon::createFromFormat('m/d/Y', $date);
        $name = trim($name);
        $amount = str_replace(['$', ',', '.'], '', $amount);
        $amount = -1 * floatval(trim($amount)) / 100;

        $transaction = \App\Models\Transaction::query()
            ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
            ->where('name', 'like', substr($name, 0, 58) . '%')
            ->where('amount', $amount)
            //->ddRawSql()
            ->first();

        if (!$transaction) {
            preg_match('/Conf# ([0-9a-zA-Z]\b)/', $name, $name_fields);
            if ($name_fields[1] ?? false) {
                $conf = trim($name_fields[1]);
                $transaction = \App\Models\Transaction::query()
                    ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
                    ->where('name', 'like', "%$conf%")
                    ->where('amount', $amount)
                    ->first();
            }
        }

        if (!$transaction) {
            echo "$count - $date $name $amount\n";
            $transaction = \App\Models\Transaction::query()
                ->where('created_at', 'like', $date->format('Y-m-d') . ' %')
                ->where('name', 'like', substr($name, 0, 58) . '%')
                ->where('amount', $amount)
                ->dumpRawSql()
            ;
            dd('saving', [
                'account_id' => $account_id,
                'created_at' => $date,
                'currency' => 'USD',
                'name' => $name,
                'amount' => $amount,
            ]);
            $transaction = \App\Models\Transaction::create([
                'account_id' => $account_id,
                'created_at' => $date,
                'currency' => 'USD',
                'name' => $name,
                'amount' => $amount,
            ]);
            echo '<hr>';
            $count++;
        }

        //$transaction->categories()->attach(1);
    }
    */
});

Route::get('/pull/{id?}', function ($id = null) {
    die('no');
    if ($id) {
        $account = \App\Models\LinkedAccount::find($id);
    } else {
        $account = \App\Models\LinkedAccount::first();
    }
    \App\Actions\PullLinkedAccountTransactionsAction::run($account);
});
require __DIR__.'/auth.php';
