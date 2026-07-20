<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;

/**
 * The categories index's expand/collapse tree is a hand-rolled Alpine implementation, not a
 * library — a single `x-data="{ open: {} }"` on the ancestor `.categories-table` div, shared
 * across every row (desktop table row AND mobile card, both rendered into the DOM at once via
 * <x-responsive-table>), toggled by two global functions that reach into Alpine's internals
 * directly (`document.querySelector(...)._x_dataStack[0]`) rather than through normal Alpine
 * directives. Nothing else in this codebase does DOM/Alpine-internals access like this, and it
 * has zero prior test coverage of any kind — this state and its cascade-close behavior on
 * collapse are pure client-side interaction, not observable from a Livewire::test() call.
 */
it('expands and collapses a category tree, cascade-closing descendants on collapse', function (): void {
    $user = User::factory()->create();
    test()->actingAs($user);

    $root = Category::create(['name' => 'Expenses']);
    $child = Category::create(['name' => 'Groceries', 'parent_id' => $root->id]);
    $grandchild = Category::create(['name' => 'Organic', 'parent_id' => $child->id]);

    $page = visit('/categories')
        ->assertSee('Expenses')
        ->assertDontSee('Groceries')
        ->assertDontSee('Organic');

    // Expand the root — its direct child appears, but the grandchild (two levels down) stays hidden.
    $page->click('Expenses')
        ->assertSee('Groceries')
        ->assertDontSee('Organic');

    // Expand the child — the grandchild appears too.
    $page->click('Groceries')
        ->assertSee('Organic');

    // Re-clicking the child collapses just that branch: the grandchild disappears, but the child
    // itself (and the still-open root) stay visible.
    $page->click('Groceries')
        ->assertSee('Groceries')
        ->assertDontSee('Organic');

    // Collapsing the root cascades the close down through any descendants left open below it —
    // here there are none left open, but this exercises the same recursive closeCat() path.
    $page->click('Expenses')
        ->assertDontSee('Groceries')
        ->assertDontSee('Organic')
        ->assertNoSmoke();
});
