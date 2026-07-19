<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetItemTransactionsGet extends Endpoint
{
    public string $path = 'transactions/get';

    public string $method = 'POST';
}
