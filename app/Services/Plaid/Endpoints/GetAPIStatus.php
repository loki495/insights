<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetAPIStatus extends Endpoint
{
    public string $path = 'api/v2/status.json';

    public string $method = 'GET';
}
