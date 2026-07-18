<?php

declare(strict_types=1);

use App\Models\User;

test('the all-categories reports page loads without a specific category', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    // No {category} route segment -> $category is null in the component's
    // mount(). Regression test: this used to 500 because both the report
    // page's own mount() and the nested transactions component's
    // getTransactionsQuery() read ->id off a null model/account.
    $response = $this->get('/reports/category');

    $response->assertStatus(200);
    $response->assertSee('All Categories');
});
