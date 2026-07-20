<?php

declare(strict_types=1);

namespace App\Services\Plaid;

use App\Models\OriginalCategory;
use App\Services\Curl\API;

/**
 * @method static array{accounts: ?array<int, array<string, mixed>>, added: ?array<int, array<string, mixed>>, removed: ?array<int, array<string, mixed>>, modified: ?array<int, array<string, mixed>>, has_more: bool, next_cursor: string} getItemTransactions(array{access_token: string} $data)
 * @method static array{item: array{institution_name: ?string}} getItemInfo(array{access_token: string} $data)
 * @method static array{link_token: string} getLinkToken(array<string, mixed> $data)
 * @method static array{item_id: string, access_token: string} exchangePublicToken(array{public_token: string} $data)
 * @method static array{status: array<string, mixed>, page: array{name: string}} getAPIStatus(array<string, mixed> $data = [])
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

    /**
     * @param  array<string, mixed>  $transactionInfo
     */
    public function resolveCategory(array $transactionInfo): ?OriginalCategory
    {
        $path = $transactionInfo['category'] ?? null;
        $plaidId = $transactionInfo['category_id'] ?? null;
        $pf = $transactionInfo['personal_finance_category'] ?? [];

        if (! is_array($path) || $path === [] || ! $plaidId) {
            return null;
        }

        return upsertPlaidCategory($path, (string) $plaidId, $pf);
    }
}
