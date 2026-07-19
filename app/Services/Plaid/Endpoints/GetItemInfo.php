<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetItemInfo extends Endpoint
{
    public string $path = 'item/get';

    public string $method = 'POST';
}
