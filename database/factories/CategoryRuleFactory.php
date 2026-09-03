<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryRule>
 */
class CategoryRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'name' => fake()->words(3, true),
            'match_type' => 'all',
            'priority' => 0,
            'active' => true,
        ];
    }
}
