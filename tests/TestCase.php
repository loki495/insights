<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase migrates, so a suite pointed at a real database (a developer's
     * .env or container environment winning over phpunit.xml) stops before touching it.
     *
     * @return array<class-string, class-string>
     */
    #[\Override]
    protected function setUpTraits(): array
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! self::isTestDatabase($database)) {
            throw new RuntimeException("Refusing to run tests against database '{$database}' on connection '{$connection}': use :memory: or a database whose name ends in _test.");
        }

        return parent::setUpTraits();
    }

    public static function isTestDatabase(string $database): bool
    {
        // Parallel runs give each worker its own copy, named <database>_test_<token>.
        return $database === ':memory:' || preg_match('/_test(_\d+)?$/', $database) === 1;
    }
}
