<?php

declare(strict_types=1);

use App\Actions\DecorateCategoryColorsForUserAction;
use App\Livewire\Concerns\HasCategoryAssignment;
use App\Models\Category;
use App\Models\User;
use Livewire\Component;

it('gives two users adopting the same shared category their own independent color', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $category = categoryFor($userA, 'Groceries', color: '#111111');
    categoryFor($userB, 'Groceries', color: '#222222');

    $decoratedForA = clone $category;
    $decoratedForB = clone $category;

    DecorateCategoryColorsForUserAction::run($userA, [$decoratedForA]);
    DecorateCategoryColorsForUserAction::run($userB, [$decoratedForB]);

    expect($decoratedForA->color)->toBe('#111111')
        ->and($decoratedForB->color)->toBe('#222222');
});

it('falls back to the default color for a category the user has not adopted', function (): void {
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Unadopted']);

    DecorateCategoryColorsForUserAction::run($user, [$category]);

    expect($category->color)->toBe('#3b82f6');
});

it('surfaces a user\'s own color through the categoryPickerOptions computed', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    categoryFor($userA, 'Groceries', color: '#111111');
    categoryFor($userB, 'Groceries', color: '#222222');

    $makeComponent = fn (): Component => new class extends Component
    {
        use HasCategoryAssignment;

        public bool $chartNeedsRefresh = false;
    };

    // #[Computed] caches per component instance, so this uses a fresh instance per user rather
    // than reusing one across the actingAs() switch — otherwise the second call would just
    // return userA's cached result regardless of which user is now acting.
    test()->actingAs($userA);
    $optionsA = $makeComponent()->categoryPickerOptions();
    expect(collect($optionsA)->firstWhere('name', 'Groceries')['color'])->toBe('#111111');

    test()->actingAs($userB);
    $optionsB = $makeComponent()->categoryPickerOptions();
    expect(collect($optionsB)->firstWhere('name', 'Groceries')['color'])->toBe('#222222');
});
