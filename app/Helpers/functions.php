<?php

declare(strict_types=1);

function plaid()
{
    return app(\App\Services\Plaid\PlaidService::class, ['environment' => config('plaid.environment')]);
}

function currency($amount, $currency = 'USD'): string
{
    match ($currency) {
        'USD' => $symbol = '$',
    };

    $color = 'zinc-700';
    $darkColor = 'white';
    if ($amount < 0) {
        $amount *= -1;
        $symbol = '-'.$symbol;
        $color = 'red-700';
        $darkColor = 'red-400';
    }

    return '<span class="text-'.$color.' dark:text-'.$darkColor.'">'.$symbol.number_format($amount, 2, '.', ',').'</span>';
}
