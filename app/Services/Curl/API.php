<?php

declare(strict_types=1);

namespace App\Services\Curl;

class API
{
    /** @var array<string, mixed> */
    public array $endpoints = [];

    /** @var array<string, string> */
    public array $baseHeaders = [];

    public function __construct(
        public readonly string $type,
        public readonly string $baseUrl,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function addBaseHeaders(array $headers): void
    {
        $this->baseHeaders = $headers;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function __call(string $class, array $arguments): array
    {
        $className = '\\App\\Services\\'.ucwords($this->type).'\\Endpoints\\'.ucwords($class);
        if (! class_exists($className)) {
            throw new \Exception('Unknown endpoint: '.$class.' ('.$className.')');
        }

        $endpoint = new $className;
        $data = $arguments['data'] ?? [];
        $headers = [
            ...$arguments['headers'] ?? [],
            ...$this->baseHeaders,
        ];

        $url = $this->baseUrl.$endpoint->path;

        $request = new Request(
            $url,
            $endpoint->method,
        )->addHeader('Content-Type', 'application/json');

        foreach ($headers as $key => $value) {
            $request->addHeader($key, $value);
        }

        foreach ($data as $key => $value) {
            $request->addDataItem($key, $value);
        }

        return $request->makeRequest();
    }
}
