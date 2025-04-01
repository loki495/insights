<?php

declare(strict_types=1);

namespace App\Services\Curl;

class Request
{
    public $headers;

    public $data;

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

    public function makeRequest(): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->url);

        if ($this->method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($this->headers['Content-Type'] === 'application/json') {
                if (! empty($this->data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->data ?? []));
                }
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->data ?? []);
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->data ?? []));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $headers = array_map(fn ($header): string => "$header: {$this->headers[$header]}", array_keys($this->headers ?? []));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? []);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception('Curl error: '.curl_error($ch));
        }

        $response = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Json error: '.json_last_error_msg());
        }

        if (isset($response['error'])) {
            throw new \Exception($response['error']);
        }

        return $response;
    }
}
