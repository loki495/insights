<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

it('shows a validation error instead of crashing when creating with a blank name', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    Livewire::test('admin.categories.edit')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Category::count())->toBe(0);
});

it('shows a validation error instead of crashing when editing to a blank name', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);
    $category = categoryFor($user, 'Groceries');

    Livewire::test('admin.categories.edit', ['category' => $category])
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name']);

    expect($category->fresh()->name)->toBe('Groceries');
});
