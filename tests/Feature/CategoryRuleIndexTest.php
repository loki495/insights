<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use Livewire\Livewire;

it('only lists the acting user\'s own rules', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    CategoryRule::factory()->for($user)->for(Category::factory()->create(['name' => 'Mine']))->create(['name' => 'My Rule']);

    $stranger = User::factory()->create();
    CategoryRule::factory()->for($stranger)->for(Category::factory()->create(['name' => 'Not Mine']))->create(['name' => 'Their Rule']);

    $test = Livewire::test('admin.category-rules.index');

    $test->assertSee('My Rule')->assertDontSee('Their Rule');
});

it('toggleActive flips a rule\'s active state', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $rule = CategoryRule::factory()->for($user)->for(Category::factory())->create(['active' => true]);

    $test = Livewire::test('admin.category-rules.index');
    $test->call('toggleActive', $rule->id);

    expect($rule->fresh()->active)->toBeFalse();
});

it('toggleActive refuses to touch another user\'s rule', function (): void {
    $owner = User::factory()->create();
    $rule = CategoryRule::factory()->for($owner)->for(Category::factory())->create(['active' => true]);

    $stranger = User::factory()->create();
    test()->actingAs($stranger);

    $test = Livewire::test('admin.category-rules.index');
    $test->call('toggleActive', $rule->id)->assertForbidden();

    expect($rule->fresh()->active)->toBeTrue();
});

it('move swaps priority with the next rule up or down', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $first = CategoryRule::factory()->for($user)->for(Category::factory())->create(['priority' => 0]);
    $second = CategoryRule::factory()->for($user)->for(Category::factory())->create(['priority' => 1]);

    $test = Livewire::test('admin.category-rules.index');
    $test->call('move', $first->id, 'down');

    expect($first->fresh()->priority)->toBe(1)
        ->and($second->fresh()->priority)->toBe(0);
});

it('move does nothing at the boundary (no rule above the first, or below the last)', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $only = CategoryRule::factory()->for($user)->for(Category::factory())->create(['priority' => 0]);

    $test = Livewire::test('admin.category-rules.index');
    $test->call('move', $only->id, 'up');
    $test->call('move', $only->id, 'down');

    expect($only->fresh()->priority)->toBe(0);
});

it('delete removes a rule', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $rule = CategoryRule::factory()->for($user)->for(Category::factory())->create();

    $test = Livewire::test('admin.category-rules.index');
    $test->call('delete', $rule->id);

    expect(CategoryRule::find($rule->id))->toBeNull();
});

it('delete refuses to remove another user\'s rule', function (): void {
    $owner = User::factory()->create();
    $rule = CategoryRule::factory()->for($owner)->for(Category::factory())->create();

    $stranger = User::factory()->create();
    test()->actingAs($stranger);

    $test = Livewire::test('admin.category-rules.index');
    $test->call('delete', $rule->id)->assertForbidden();

    expect(CategoryRule::find($rule->id))->not->toBeNull();
});
