<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetItemAccounts extends Endpoint
{
    public string $path = 'accounts/get';

    public string $method = 'POST';
}
