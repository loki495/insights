<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetLinkToken extends Endpoint
{
    public $path = 'link/token/create';

    public $method = 'POST';
}
