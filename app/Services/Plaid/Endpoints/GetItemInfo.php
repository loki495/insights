<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetItemInfo extends Endpoint
{
    public $path = 'item/get';

    public $method = 'POST';
}
