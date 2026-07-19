<?php

declare(strict_types=1);

namespace App\Services\Plaid;

use App\Models\OriginalCategory;
use App\Services\Curl\API;

/**
 * @method static array{accounts: ?array<array>, added: ?array<array>, removed: ?array<array>, modified: ?array<array>} getItemTransactions(array{access_token: string} $data)
 *
 */

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

        return $this->environment;
    }

    public function baseUrl(): string
    {
        return 'https://'.$this->environment.'.plaid.com/';
    }

    public function resolveCategory(array $transactionInfo): ?OriginalCategory
    {
        $path = $transactionInfo['category'] ?? null;
        $plaidId = $transactionInfo['category_id'] ?? null;
        $pf = $transactionInfo['personal_finance_category'] ?? [];

        if (!is_array($path) || empty($path) || !$plaidId) {
            return null;
        }

        return upsertPlaidCategory($path, (string) $plaidId, $pf);
    }
}
