<?php

declare(strict_types=1);

namespace App\Services\Plaid;

use App\Services\Curl\API;

class PlaidService extends API
{
    const ENV_SANDBOX = 'sandbox';
    const ENV_PRODUCTION = 'production';
    const ENV_STATUS = 'status';

    public function __construct(
        public readonly string $environment,
        public readonly string $clientId,
        public readonly string $apiKey,

    ) {
        parent::__construct(
            'plaid',
            $this->baseUrl(),
        );
    }

    public function baseUrl(): string
    {
        return 'https://'.$this->environment.'.plaid.com/';
    }
}
