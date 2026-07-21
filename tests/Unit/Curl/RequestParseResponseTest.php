<?php

declare(strict_types=1);

use App\Services\Curl\CurlRequestException;
use App\Services\Curl\Request;

it('returns the decoded body for a successful response', function (): void {
    $result = Request::parseResponse(json_encode(['accounts' => [], 'has_more' => false]), 200);

    expect($result)->toBe(['accounts' => [], 'has_more' => false]);
});

it('throws on a non-2xx HTTP status even without a Plaid-shaped error body', function (): void {
    Request::parseResponse(json_encode(['message' => 'Service Unavailable']), 503);
})->throws(CurlRequestException::class);

it('throws on a Plaid error_type/error_code payload even with a 200 status', function (): void {
    $body = json_encode([
        'error_type' => 'ITEM_ERROR',
        'error_code' => 'ITEM_LOGIN_REQUIRED',
        'error_message' => 'the login details of this item have changed',
    ]);

    Request::parseResponse($body, 200);
})->throws(CurlRequestException::class, 'the login details of this item have changed');

it('does not false-positive on a generic top-level "error" key the way the old check did', function (): void {
    // Regression guard: the previous implementation checked isset($response['error']), which
    // Plaid's real payloads never set — this key should be inert now.
    $result = Request::parseResponse(json_encode(['error' => null, 'has_more' => false]), 200);

    expect($result)->toHaveKey('has_more', false);
});

it('throws on invalid JSON', function (): void {
    Request::parseResponse('not json', 200);
})->throws(RuntimeException::class);

it('exposes the HTTP status and raw response on the exception', function (): void {
    try {
        Request::parseResponse(json_encode(['error_type' => 'INVALID_REQUEST', 'error_code' => 'MISSING_FIELDS']), 400);
        $this->fail('Expected CurlRequestException to be thrown.');
    } catch (CurlRequestException $e) {
        expect($e->httpStatus)->toBe(400);
        expect($e->response)->toHaveKey('error_code', 'MISSING_FIELDS');
    }
});
