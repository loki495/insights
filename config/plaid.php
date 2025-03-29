<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'clientId' => env('PLAID_CLIENT_ID', ''),
    'environment' => env('PLAID_ENVIRONMENT', 'sandbox'),
    'secret_production' => env('PLAID_API_KEY_PRODUCTION', ''),
    'secret_sandbox' => env('PLAID_API_KEY_SANDBOX', ''),

];
