<?php

declare(strict_types=1);

namespace App\Services\Curl;

class CurlRequestException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $response = [],
    ) {
        parent::__construct($message);
    }
}
