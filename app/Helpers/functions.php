<?php

function plaid() {
    return app(\App\Services\Plaid\PlaidService::class, ['environment' => config('plaid.environment')]);
}

function currency($amount, $currency = 'USD')
{
    match ($currency) {
        'USD' => $symbol = '$',
    };

    $color = 'white';
    if ($amount < 0) {
        $amount = $amount * -1;
        $symbol = '-' . $symbol;
        $color = 'red-500';
    }
    return '<span class="text-' . $color . '">' . $symbol . number_format($amount, 2, '.', ',') . '</span>';
}
