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
    ) {
        parent::__construct(
            'plaid',
            $this->baseUrl(),
        );

        $this->addBaseHeaders([
            'PLAID-CLIENT-ID' => $this->clientId,
            'PLAID-SECRET' => $this->getSecret(),
        ]);
    }

    public function getSecret(): string
    {
        if ($this->environment === self::ENV_SANDBOX) {
            return config('plaid.secret_sandbox');
        }

        if ($this->environment === self::ENV_PRODUCTION) {
            return config('plaid.secret_production');
        }
    }

    public function baseUrl(): string
    {
        return 'https://'.$this->environment.'.plaid.com/';
    }
}
