<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Several category/type names (e.g. "Income") are reused verbatim elsewhere on transaction-list
 * pages — the bulk type-assign dropdown, the type-editor modal, other rows' pills — as hidden
 * (x-show="false"/x-cloak) DOM nodes that still exist and still match a plain text locator, just
 * with zero rendered size. A scoped `button:visible:has-text(...)` selector (Playwright's own
 * `:visible` pseudo-class) reliably targets the one actually-visible match instead of hanging on
 * an ambiguous one. Shared across Browser tests rather than duplicated per file.
 */
function clickVisibleButton(string $text): string
{
    return sprintf('button:visible:has-text(%s)', json_encode($text));
}
