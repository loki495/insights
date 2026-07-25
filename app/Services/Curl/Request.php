<?php

declare(strict_types=1);

namespace App\Services\Curl;

class Request
{
    /** @var array<string, string> */
    public array $headers = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public function __construct(
        public readonly string $url,
        public readonly string $method,
    ) {}

    public function addHeader(string $key, string $value): static
    {
        $this->headers[$key] = $value;

        return $this;
    }

    public function addDataItem(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * @return array{url: string, method: string, headers: array<string, string>, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'method' => $this->method,
            'headers' => $this->headers,
            'data' => $this->data,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function makeRequest(): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->url);

        if ($this->method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (($this->headers['Content-Type'] ?? null) === 'application/json') {
                if ($this->data !== []) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->data));
                }
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->data);
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->data));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $headers = array_map(fn (string $header): string => "$header: {$this->headers[$header]}", array_keys($this->headers));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlErrno !== 0) {
            throw new \RuntimeException('Curl error: '.$curlError);
        }

        return self::parseResponse($response, $httpCode);
    }

    /**
     * Split out from makeRequest() so the error-detection logic (the part that actually matters
     * here) is unit-testable without a real curl/network round trip.
     *
     * @return array<string, mixed>
     */
    public static function parseResponse(string $body, int $httpCode): array
    {
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Json error: '.json_last_error_msg());
        }

        // Plaid error payloads never use a top-level "error" key — they use error_type/error_code
        // (see https://plaid.com/docs/errors/), and can arrive on a 2xx status. Checking the HTTP
        // status too catches non-Plaid-shaped failures (proxy errors, maintenance pages, etc.).
        if ($httpCode >= 400 || isset($decoded['error_type']) || isset($decoded['error_code'])) {
            throw new CurlRequestException(
                message: $decoded['error_message'] ?? $decoded['error_type'] ?? "HTTP {$httpCode} error",
                httpStatus: $httpCode,
                response: is_array($decoded) ? $decoded : [],
            );
        }

        return $decoded;
    }
}
