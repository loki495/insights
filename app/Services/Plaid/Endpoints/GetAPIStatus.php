<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

class GetAPIStatus
{
    public $path = 'api/v2/status.json';

    public $method = 'GET';
}
