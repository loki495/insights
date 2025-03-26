<?php

declare(strict_types=1);

namespace App\Services\Plaid;

class Endpoint
{
    /**
     * @param array<string,string> $data
     */
    public function __construct(
        public readonly array $data = [],
    ) { }
}
