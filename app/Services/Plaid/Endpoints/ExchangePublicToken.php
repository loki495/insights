<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class ExchangePublicToken extends Endpoint
{
    public $path = 'item/public_token/exchange';

    public $method = 'POST';
}
