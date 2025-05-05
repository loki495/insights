<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetLiabilities extends Endpoint
{
    public $path = 'liabilities/get';

    public $method = 'POST';

}
