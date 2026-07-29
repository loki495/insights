<?php

declare(strict_types=1);
use App\Models\Category;
use App\Models\User;
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

pest()->tia()->locally();

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

/**
 * Categories are a shared, deduplicated (parent_id, name) vocabulary that a user must adopt (via
 * the category_user pivot) before it shows up in their picker/reports/lists — a bare
 * Category::create() is no longer enough on its own for tests exercising anything user-facing.
 * Mirrors app/Actions/CreateOrAdoptCategoryAction.php's find-or-create + adopt behavior.
 */
function categoryFor(User $user, string $name, ?int $parentId = null, ?string $color = null): Category
{
    $category = Category::firstOrCreate(['parent_id' => $parentId ?: 0, 'name' => $name]);
    $user->categories()->syncWithoutDetaching([$category->id => ['color' => $color ?: '#3b82f6']]);

    return $category;
}
