<?php

declare(strict_types=1);

namespace App\Services\Plaid\Endpoints;

use App\Services\Plaid\Endpoint;

class GetItemTransactions extends Endpoint
{
    public string $path = 'transactions/sync';

    public string $method = 'POST';
}
