<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;

final readonly class TransactionFilters
{
    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, string>  $typeFilters
     */
    public function __construct(
        public ?Account $account = null,
        public array $accountIds = [],
        public ?int $categoryId = null,
        public ?int $originalCategoryId = null,
        public bool $onlyUncategorized = false,
        public array $typeFilters = [],
        public string $amountMin = '',
        public string $amountMax = '',
        public string $dateFrom = '',
        public string $dateTo = '',
        public string $search = '',
    ) {}
}
