<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plaid API Credentials
    |--------------------------------------------------------------------------
    |
    | Get these from your Plaid dashboard (https://dashboard.plaid.com/).
    | A free "sandbox" account is enough for local development — it gives you
    | fake institutions/transactions without needing a production Plaid
    | agreement. See README.md for the full linking flow.
    |
    */

    'clientId' => env('PLAID_CLIENT_ID', ''),
    'environment' => env('PLAID_ENVIRONMENT', 'sandbox'),
    'secret_production' => env('PLAID_API_KEY_PRODUCTION', ''),
    'secret_sandbox' => env('PLAID_API_KEY_SANDBOX', ''),

];
