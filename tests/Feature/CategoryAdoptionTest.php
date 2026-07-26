<?php

declare(strict_types=1);

use App\Actions\CreateOrAdoptCategoryAction;
use App\Actions\FindOrCreateCategoryAction;
use App\Models\Category;
use App\Models\User;

it('dedupes case-insensitively by (parent_id, name) when two users create the "same" category', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $categoryA = CreateOrAdoptCategoryAction::run($userA, null, 'Coffee', '#111111');
    $categoryB = CreateOrAdoptCategoryAction::run($userB, null, 'coffee', '#222222');

    expect($categoryA->id)->toBe($categoryB->id);
    expect(Category::count())->toBe(1);

    expect($userA->categories()->find($categoryA->id)->pivot->color)->toBe('#111111');
    expect($userB->categories()->find($categoryB->id)->pivot->color)->toBe('#222222');
});

it('does not create a duplicate row for the same (parent_id, name) under different parents', function (): void {
    $user = User::factory()->create();
    $expenses = CreateOrAdoptCategoryAction::run($user, null, 'Expenses', null);
    $income = CreateOrAdoptCategoryAction::run($user, null, 'Income', null);

    $under1 = FindOrCreateCategoryAction::run($expenses->id, 'Fees');
    $under2 = FindOrCreateCategoryAction::run($income->id, 'Fees');

    expect($under1->id)->not->toBe($under2->id);
    expect(Category::where('name', 'Fees')->count())->toBe(2);
});

it('re-creating an already-adopted category updates its color rather than erroring', function (): void {
    $user = User::factory()->create();
    $category = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', '#111111');

    $again = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', '#999999');

    expect($again->id)->toBe($category->id);
    expect($user->categories()->find($category->id)->pivot->color)->toBe('#999999');
    expect($user->categories()->count())->toBe(1);
});

it('trims whitespace before matching an existing category', function (): void {
    $user = User::factory()->create();
    $category = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', null);

    $matched = FindOrCreateCategoryAction::run(null, '  Groceries  ');

    expect($matched->id)->toBe($category->id);
});

it('updates the shared category description when one is explicitly passed', function (): void {
    $user = User::factory()->create();
    $category = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', null);
    expect($category->description)->toBeNull();

    $updated = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', null, 'Food and household supplies');

    expect($updated->id)->toBe($category->id);
    expect($updated->description)->toBe('Food and household supplies');
});

it('does not touch the description when none is passed (quick-picker create flow)', function (): void {
    $user = User::factory()->create();
    $category = CreateOrAdoptCategoryAction::run($user, null, 'Groceries', null, 'Original description');

    CreateOrAdoptCategoryAction::run($user, null, 'Groceries', null);

    expect($category->fresh()->description)->toBe('Original description');
});
