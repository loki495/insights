<?php

declare(strict_types=1);

use Tests\TestCase;

it('accepts only in-memory SQLite or a *_test database', function (string $database, bool $allowed): void {
    expect(TestCase::isTestDatabase($database))->toBe($allowed);
})->with([
    'in-memory SQLite' => [':memory:', true],
    'dedicated test schema' => ['insights_test', true],
    'real schema' => ['insights', false],
    'SQLite file' => ['/var/www/html/database/database.sqlite', false],
    'test-like but not suffixed' => ['test_insights', false],
    'blank' => ['', false],
]);
