<?php

namespace Database\Factories;

use App\Models\CategoryRule;
use App\Models\CategoryRuleConditionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryRuleConditionGroup>
 */
class CategoryRuleConditionGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_rule_id' => CategoryRule::factory(),
            'match_type' => 'all',
            'position' => 0,
        ];
    }
}
